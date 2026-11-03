#!/usr/bin/env python3
"""Ensaio HTTP concorrente de dez identidades fictícias no Compose HTTPS efêmero."""
import argparse
import base64
from contextlib import ExitStack
import socket
from concurrent.futures import ThreadPoolExecutor
import hashlib
import http.cookiejar
import json
from pathlib import Path
import re
import secrets
import ssl
import statistics
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
COMPOSE = ["docker", "compose", "--project-directory", str(ROOT), "--env-file", ".env.homologacao", "-f", "compose.homologacao.yaml"]
BASE = "https://localhost:8443/ia-ganalise"
BOOTSTRAP = r'''require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(config("app.url")!=="https://localhost:8443/ia-ganalise"||config("database.default")!=="pgsql"||config("database.connections.pgsql.database")!=="ganalise_ensaio_descartavel"||config("horus.openai.enabled")||config("horus.openai.plan_enabled")||config("horus.lumo.login_enabled")||config("suite.enabled")||config("horus.mcp.enabled")||config("horus.viewer.enabled")){exit(3);}$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);'''
SEED = BOOTSTRAP + r'''$hash=Illuminate\Support\Facades\Hash::make($input["password"]);$result=[];Illuminate\Support\Facades\DB::transaction(function()use($input,$hash,&$result){for($i=0;$i<10;$i++){$role=$i%2===0?"editor":"viewer";$u=App\Models\User::forceCreate(["name"=>"Ensaio concorrente ".$i,"email"=>"pilot-".$input["nonce"]."-".$i."@example.invalid","password"=>$hash,"role"=>$role,"active"=>true,"local_login_enabled"=>true,"must_change_password"=>false]);$p=App\Models\Project::create(["name"=>"Projeto fictício ".$i,"user_id"=>$u->id]);$c=App\Models\Conversation::create(["user_id"=>$u->id,"project_id"=>$p->id]);$result[]=["id"=>$u->id,"email"=>$u->email,"role"=>$role,"project_id"=>$p->id,"conversation_id"=>$c->id];}});echo json_encode($result,JSON_THROW_ON_ERROR);'''
CLEANUP = BOOTSTRAP + r'''$users=App\Models\User::whereIn("id",$input["ids"])->where("email","like","pilot-".$input["nonce"]."-%@example.invalid")->get();foreach($users as $user){foreach(App\Models\Project::where("user_id",$user->id)->get() as $project){$uploads="uploads/".$user->id."/".$project->id;if(Illuminate\Support\Facades\Storage::disk("local")->exists($uploads)&&!Illuminate\Support\Facades\Storage::disk("local")->deleteDirectory($uploads)){throw new RuntimeException("fixture cleanup failed");}$attachments=app(App\Services\WorkspaceFiles::class)->path($project)."/attachments";if(is_dir($attachments)&&!Illuminate\Support\Facades\File::deleteDirectory($attachments)){throw new RuntimeException("fixture cleanup failed");}}Illuminate\Support\Facades\DB::table("sessions")->where("user_id",$user->id)->delete();$user->delete();}echo json_encode(["removed"=>$users->count()],JSON_THROW_ON_ERROR);'''


def php(code, value):
    result = subprocess.run(COMPOSE + ["exec", "-T", "app", "php", "-r", code], input=json.dumps(value), text=True, capture_output=True, timeout=35)
    if result.returncode != 0:
        raise RuntimeError("Falha na preparação ou limpeza do banco efêmero; nenhum ambiente externo foi selecionado.")
    return json.loads(result.stdout)


def exercise(user, foreign, password, context):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context), urllib.request.HTTPCookieProcessor(jar))
    timings = []

    def request(path, *, method="GET", data=None, token=None, expected=200, as_json=True, multipart=None, raw=False):
        headers = {"Accept": "application/json" if as_json else "text/html"}
        payload = None
        if data is not None:
            payload = json.dumps(data).encode()
            headers.update({"Content-Type": "application/json", "Origin": "https://localhost:8443", "Referer": BASE + "/app", "X-CSRF-TOKEN": token})
        if multipart is not None:
            payload, content_type = multipart
            headers.update({"Content-Type": content_type, "Origin": "https://localhost:8443", "Referer": BASE + "/app", "X-CSRF-TOKEN": token})
        start = time.perf_counter()
        try:
            response = opener.open(urllib.request.Request(BASE + path, data=payload, headers=headers, method=method), timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read()
            status = response.status
        timings.append(time.perf_counter() - start)
        if status != expected:
            raise AssertionError("Resposta inesperada em " + method + " " + path + ": " + str(status))
        return body if raw else json.loads(body) if as_json else body.decode()

    login = request("/login", as_json=False)
    token = re.search(r'name="csrf-token" content="([^"]+)"', login)
    if token is None:
        raise AssertionError("O login não forneceu token CSRF.")
    logged_in = request("/login", method="POST", data={"email": user["email"], "password": password}, token=token[1], as_json=False)
    token = re.search(r'name="csrf-token" content="([^"]+)"', logged_in)
    if token is None:
        raise AssertionError("A sessão autenticada não forneceu token CSRF.")
    bootstrap = request("/api/bootstrap")
    assert bootstrap["user"]["id"] == user["id"]
    assert [project["id"] for project in bootstrap["projects"]] == [user["project_id"]]
    assert all(item["user_id"] == user["id"] for item in bootstrap["conversations"])
    assert bootstrap["connections"] == []
    own = request("/api/conversations/" + user["conversation_id"])
    assert own["conversation"]["user_id"] == user["id"]
    request("/api/conversations/" + foreign["conversation_id"], expected=404)
    guide = request("/api/guide")
    assert "users" not in guide["pages"]
    created = request("/api/projects", method="POST", data={"name": "Segundo projeto fictício"}, token=token[1], expected=201 if user["role"] == "editor" else 403)
    if user["role"] == "editor":
        assert created["project"]["user_id"] == user["id"]
        assert created["conversation"]["project_id"] == created["project"]["id"]
    latest = request("/api/bootstrap")
    assert all(item["user_id"] == user["id"] for item in latest["projects"])
    assert len(latest["projects"]) == (2 if user["role"] == "editor" else 1)
    assert any(cookie.secure and cookie.path == "/ia-ganalise" for cookie in jar)
    return {"timings": timings, "request": request, "csrf": token[1]}



class RehearsalSocket:
    """Small bounded WebSocket client used only by the local disposable rehearsal."""

    def __init__(self, context):
        self.buffer = b""
        raw = socket.create_connection(("localhost", 8443), timeout=5)
        try:
            self.connection = context.wrap_socket(raw, server_hostname="localhost")
            key = base64.b64encode(secrets.token_bytes(16)).decode()
            upgrade = ("GET /ia-ganalise/ws/app/homologacao-local?protocol=7&client=js&version=8.4.0 HTTP/1.1\r\n"
                       "Host: localhost:8443\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n"
                       "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Key: " + key + "\r\nOrigin: https://localhost:8443\r\n\r\n")
            self.connection.sendall(upgrade.encode())
            while b"\r\n\r\n" not in self.buffer:
                self.buffer += self._read()
                if len(self.buffer) > 16000:
                    raise AssertionError("O handshake WebSocket ultrapassou o limite do ensaio.")
            head, self.buffer = self.buffer.split(b"\r\n\r\n", 1)
            lines = head.decode("ascii").split("\r\n")
            if lines[0].split(" ")[1] != "101":
                raise AssertionError("O proxy não confirmou a atualização para WebSocket.")
            headers = dict((key.strip().lower(), value.strip()) for key, value in (line.split(":", 1) for line in lines[1:]))
            expected = base64.b64encode(hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()).decode()
            if headers.get("sec-websocket-accept") != expected:
                raise AssertionError("O servidor não confirmou a chave do handshake WebSocket.")
            event = self.receive()
            if event.get("event") != "pusher:connection_established":
                raise AssertionError("A conexão em tempo real não foi estabelecida.")
            data = self.data(event)
            self.socket_id = data["socket_id"]
        except BaseException:
            getattr(self, "connection", raw).close()
            raise

    def _read(self):
        data = self.connection.recv(4096)
        if not data:
            raise AssertionError("A conexão WebSocket encerrou antes da confirmação.")
        return data

    def _take(self, size):
        while len(self.buffer) < size:
            self.buffer += self._read()
        result, self.buffer = self.buffer[:size], self.buffer[size:]
        return result

    def _send_frame(self, payload, opcode=1):
        if len(payload) > 64000:
            raise AssertionError("O evento excedeu o limite do ensaio WebSocket.")
        mask = secrets.token_bytes(4)
        length = len(payload)
        header = bytes([0x80 | opcode, 0x80 | length]) if length < 126 else bytes([0x80 | opcode, 0x80 | 126]) + length.to_bytes(2, "big")
        self.connection.sendall(header + mask + bytes(value ^ mask[index % 4] for index, value in enumerate(payload)))

    def send(self, event):
        self._send_frame(json.dumps(event).encode())

    def receive(self, timeout=5):
        self.connection.settimeout(timeout)
        while True:
            header = self._take(2)
            if header[0] & 0xF0 != 0x80 or header[1] & 0x80:
                raise AssertionError("O ensaio recebeu um frame fragmentado, comprimido ou mascarado inesperado.")
            opcode, length = header[0] & 0x0F, header[1] & 0x7F
            if length == 126:
                length = int.from_bytes(self._take(2), "big")
            elif length == 127:
                length = int.from_bytes(self._take(8), "big")
            if length > 64000:
                raise AssertionError("O evento recebido excedeu o limite do ensaio WebSocket.")
            payload = self._take(length)
            if opcode == 9:
                self._send_frame(payload, 10)
                continue
            if opcode != 1:
                raise AssertionError("O ensaio esperava um evento de texto em tempo real.")
            event = json.loads(payload)
            if event.get("event") == "pusher:ping":
                self.send({"event": "pusher:pong", "data": {}})
                continue
            return event

    @staticmethod
    def data(event):
        data = event.get("data", {})
        return json.loads(data) if isinstance(data, str) else data

    def close(self):
        self.connection.close()


def realtime_rehearsal(results, users, context, nonce):
    with ExitStack() as cleanup:
        subscribers = []
        signatures = []
        for index, (result, user) in enumerate(zip(results, users)):
            connection = RehearsalSocket(context)
            cleanup.callback(connection.close)
            channel = "private-users." + str(user["id"])
            auth = result["request"]("/broadcasting/auth", method="POST", token=result["csrf"], data={"socket_id": connection.socket_id, "channel_name": channel})
            foreign = "private-users." + str(users[(index + 1) % len(users)]["id"])
            result["request"]("/broadcasting/auth", method="POST", token=result["csrf"], data={"socket_id": connection.socket_id, "channel_name": foreign}, expected=403)
            connection.send({"event": "pusher:subscribe", "data": {"channel": channel, "auth": auth["auth"]}})
            accepted = connection.receive()
            assert accepted.get("event") == "pusher_internal:subscription_succeeded" and accepted.get("channel") == channel, "A assinatura do próprio canal não foi confirmada."
            subscribers.append((connection, channel))
            signatures.append(auth["auth"])

        probe = RehearsalSocket(context)
        cleanup.callback(probe.close)
        own = "private-users." + str(users[0]["id"])
        foreign = "private-users." + str(users[1]["id"])
        signature = results[0]["request"]("/broadcasting/auth", method="POST", token=results[0]["csrf"], data={"socket_id": probe.socket_id, "channel_name": own})
        probe.send({"event": "pusher:subscribe", "data": {"channel": foreign, "auth": signature["auth"]}})
        rejected = probe.receive()
        assert rejected.get("event") == "pusher:error" and probe.data(rejected).get("code") == 4009, "O WebSocket não recusou uma assinatura usada no canal de outra identidade."

        signatures.append(signature["auth"])
        probe.send({"event": "pusher:subscribe", "data": {"channel": own, "auth": signatures[0]}})
        rejected = probe.receive()
        assert rejected.get("event") == "pusher:error" and probe.data(rejected).get("code") == 4009, "O WebSocket não recusou a assinatura de outra conexão."

        markers = {str(user["id"]): "synthetic-realtime-" + secrets.token_hex(16) for user in users}
        broadcast = BOOTSTRAP + r'''if(config("broadcasting.default")!=="reverb"){exit(3);}$users=App\Models\User::whereIn("id",array_keys($input["markers"]))->where("email","like","pilot-".$input["nonce"]."-%@example.invalid")->get();if($users->count()!==10){exit(3);}foreach($users as $user){$marker=$input["markers"][(string)$user->id];if(!is_string($marker)||!preg_match("/^synthetic-realtime-[a-f0-9]{32}$/D",$marker)){exit(3);}event(new App\Events\WorkspaceChanged($user->id,$marker));}echo json_encode(["broadcasts"=>$users->count()],JSON_THROW_ON_ERROR);'''
        assert php(broadcast, {"nonce": nonce, "markers": markers}) == {"broadcasts": len(users)}
        for (connection, channel), user in zip(subscribers, users):
            event = connection.receive()
            assert event.get("event") == "workspace.changed" and event.get("channel") == channel, "Um evento chegou a uma identidade incorreta."
            assert connection.data(event) == {"run_id": markers[str(user["id"])]}, "O evento não pertence ao usuário conectado."
            try:
                connection.receive(timeout=0.25)
            except TimeoutError:
                pass
            else:
                raise AssertionError("O canal recebeu eventos adicionais de outra identidade.")
        captured = subprocess.run(COMPOSE + ["logs", "--no-color", "app", "web", "reverb", "tls-test"], capture_output=True, text=True, check=True, timeout=15)
        logs = captured.stdout + captured.stderr
        assert all(signature not in logs for signature in signatures), "Uma autorização WebSocket fictícia apareceu nos logs."
    return {"subscribed_identities": len(users), "own_channel_authorizations": len(users), "foreign_channel_denials": len(users), "cross_channel_signature_replay_rejected": True, "cross_socket_signature_replay_rejected": True, "authorizations_absent_from_logs": True, "own_event_received_per_identity": True, "foreign_events_observed": 0, "tls_certificate_verified": True, "scope": "synthetic WorkspaceChanged events through real Reverb and authenticated HTTPS; no BI or inference"}


def attachment_rehearsal(results, users, fixture, *, text=False):
    data = fixture.read_bytes()
    if not data or len(data) > 10 * 1024 * 1024 or (not text and not data.startswith(b"%PDF-")):
        raise ValueError("Use somente um arquivo fictício válido de até 10 MB para o ensaio.")
    if text:
        data.decode("utf-8")
    filename, mime = ("homologacao-limite.txt", "text/plain") if text else ("homologacao-digitalizado.pdf", "application/pdf")
    boundary = "ganalise-rehearsal-" + secrets.token_hex(16)
    multipart = ("--" + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="' + filename + '"\r\nContent-Type: ' + mime + "\r\n\r\n").encode() + data + ("\r\n--" + boundary + "--\r\n").encode()
    editor, viewer = results[0], results[1]
    artifact = editor["request"]("/api/projects/" + users[0]["project_id"] + "/files", method="POST", token=editor["csrf"], multipart=(multipart, "multipart/form-data; boundary=" + boundary), expected=201)
    deadline = time.perf_counter() + 40
    current = None
    while time.perf_counter() < deadline:
        bootstrap = editor["request"]("/api/bootstrap")
        current = next((item for item in bootstrap["artifacts"] if item["id"] == artifact["id"]), None)
        state = current.get("metadata", {}).get("extraction", {}) if current else {}
        if state.get("status") in ("ready", "failed", "empty"):
            break
        time.sleep(0.25)
    assert current is not None and state.get("status") == "ready", "O worker não preparou o anexo fictício."
    if text:
        assert state.get("characters", 0) > 0
        if len(data) > 180000:
            assert state.get("truncated") is True
    else:
        assert state.get("visual_pdf") is True
        assert [page["page"] for page in state["visual_pages"]] == [1, 2, 3]
    assert current.get("metadata", {}).get("size") == len(data)
    original = editor["request"]("/api/artifacts/" + artifact["id"] + "/download", raw=True)
    assert hashlib.sha256(original).digest() == hashlib.sha256(data).digest()
    viewer["request"]("/api/artifacts/" + artifact["id"] + "/download", expected=404)
    return {"multipart_upload_with_csrf": True, "documents_worker_preparation": True, "fixture_bytes": len(data), "kind": "text" if text else "visual_pdf", "visual_pages": [] if text else [1, 2, 3], "truncated": state.get("truncated"), "original_sha256_preserved": True, "foreign_download_returns_404": True, "sent_to_model": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--disposable", action="store_true", required=True)
    parser.add_argument("--realtime", action="store_true", help="Confere canais privados e entrega de eventos fictícios às dez sessões HTTPS")
    fixtures = parser.add_mutually_exclusive_group()
    fixtures.add_argument("--pdf-fixture", type=Path, help="PDF digitalizado fictício com pelo menos três páginas, enviado somente ao ensaio HTTPS local")
    fixtures.add_argument("--text-fixture", type=Path, help="Texto UTF-8 inteiramente fictício de até 10 MB, enviado somente ao ensaio HTTPS local")
    args = parser.parse_args()
    context = ssl.create_default_context(cafile=str(ROOT / "backups/homologacao-local/localhost.crt"))
    nonce = secrets.token_hex(8)
    password = "SyntheticA1-" + secrets.token_urlsafe(24)
    users = php(SEED, {"nonce": nonce, "password": password})
    try:
        with ThreadPoolExecutor(max_workers=10) as pool:
            futures = [pool.submit(exercise, user, users[(index + 1) % len(users)], password, context) for index, user in enumerate(users)]
            results = [future.result() for future in futures]
            samples = sorted(value for result in results for value in result["timings"])
        report = {"ok": True, "concurrent_users": 10, "editors": 5, "viewers": 5, "http_operations": len(samples), "own_projects_verified": True, "foreign_conversations_return_404": True, "viewer_creation_return_403": True, "editor_project_creation_verified": True, "secure_scoped_sessions": True, "operation_latency_seconds": {"median": round(statistics.median(samples), 3), "p95": round(samples[int((len(samples)-1)*0.95)], 3), "max": round(samples[-1], 3)}, "scope": "local HTTPS and application ownership; no native BI calls or model inference"}
        if args.realtime:
            report["realtime"] = realtime_rehearsal(results, users, context, nonce)
        if args.pdf_fixture is not None:
            report["attachment"] = attachment_rehearsal(results, users, args.pdf_fixture)
        elif args.text_fixture is not None:
            report["attachment"] = attachment_rehearsal(results, users, args.text_fixture, text=True)
    finally:
        cleanup = php(CLEANUP, {"nonce": nonce, "ids": [user["id"] for user in users]})
        if cleanup["removed"] != len(users):
            raise AssertionError("A limpeza das contas fictícias ficou incompleta.")
    print(json.dumps(report, ensure_ascii=False))


if __name__ == "__main__":
    main()
