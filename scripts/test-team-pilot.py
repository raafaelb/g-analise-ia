#!/usr/bin/env python3
"""Ensaio HTTP concorrente de dez identidades fictícias no Compose HTTPS efêmero."""
import argparse
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
BOOTSTRAP = r'''require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(config("app.url")!=="https://localhost:8443/ia-ganalise"||config("database.default")!=="pgsql"||config("database.connections.pgsql.database")!=="ganalise_ensaio_descartavel"||config("horus.openai.enabled")||config("horus.lumo.login_enabled")){exit(3);}$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);'''
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
