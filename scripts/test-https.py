#!/usr/bin/env python3
"""Valida HTTPS e autenticação apenas no banco efêmero de homologação."""
from pathlib import Path
import urllib.request,urllib.parse,urllib.error,http.cookiejar,ssl,re,json,subprocess,secrets,html,socket,base64,time
root=Path(__file__).resolve().parent.parent
compose=['docker','compose','--project-directory',str(root),'--env-file','.env.homologacao','-f','compose.homologacao.yaml']
password=secrets.token_urlsafe(32)
seed='''require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();if(config("database.connections.pgsql.database")!=="ganalise_ensaio_descartavel"||config("app.url")!=="https://localhost:8443/ia-ganalise"){exit(3);}$p=json_decode(stream_get_contents(STDIN),true);App\\Models\\User::updateOrCreate(["email"=>"homologacao@example.invalid"],["name"=>"Homologação efêmera","role"=>"owner","password"=>Illuminate\\Support\\Facades\\Hash::make($p["password"])]);echo "seed_ok";'''
r=subprocess.run(compose+['exec','-T','app','php','-r',seed],input=json.dumps({'password':password}),text=True,capture_output=True,check=True)
assert r.stdout=='seed_ok'
base='https://localhost:8443/ia-ganalise'
jar=http.cookiejar.CookieJar();ctx=ssl.create_default_context(cafile=str(root/'backups/homologacao-local/localhost.crt'))
opener=urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx),urllib.request.HTTPCookieProcessor(jar))
deadline=time.monotonic()+20
while True:
    try:
        response=opener.open(base+'/login',timeout=5)
        break
    except urllib.error.HTTPError as failure:
        if failure.code not in (502,503,504) or time.monotonic()>=deadline:raise
        time.sleep(0.25)
    except urllib.error.URLError as failure:
        if not isinstance(failure.reason,(ConnectionRefusedError,ConnectionResetError,TimeoutError)) or time.monotonic()>=deadline:raise
        time.sleep(0.25)
body=response.read().decode()
match=re.search(r'name="csrf-token" content="([^"]+)"',body);assert match
assets=set(re.findall(r'(?:src|href)="([^"]*(?:build/|brand/)[^"]*)"',body))
for asset in assets:
    assert asset.startswith(base+'/')
    with opener.open(asset) as f:assert f.status==200
raw=re.search(r'<script data-page="app" type="application/json">(.*?)</script>',body,re.S)[1];page=json.loads(raw)
assert page['url']=='/ia-ganalise/login',page['url']
request=urllib.request.Request(base+'/login',data=urllib.parse.urlencode({'email':'homologacao@example.invalid','password':password,'remember':'0','_token':match[1]}).encode(),headers={'Origin':'https://localhost:8443','Referer':base+'/login'})
with opener.open(request) as f:
    final=f.geturl();auth_body=f.read().decode();assert final.startswith(base+'/app'),final
with opener.open(urllib.request.Request(base+'/api/bootstrap',headers={'Accept':'application/json'})) as f:
    boot=json.load(f);assert boot['user']['email']=='homologacao@example.invalid';assert boot['preview']==False
assert any(c.secure and c.path=='/ia-ganalise' for c in jar)
def handshake(origin):
    with socket.create_connection(('localhost',8443),timeout=5) as connection:
        with ctx.wrap_socket(connection,server_hostname='localhost') as secure:
            key=base64.b64encode(secrets.token_bytes(16)).decode()
            request=('GET /ia-ganalise/ws/app/homologacao-local?protocol=7&client=js&version=8.4.0 HTTP/1.1\r\nHost: localhost:8443\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: '+key+'\r\nOrigin: '+origin+'\r\n\r\n')
            secure.sendall(request.encode())
            buffer=b''
            while b'\r\n\r\n' not in buffer:
                chunk=secure.recv(4096);assert chunk,'Handshake encerrado';buffer+=chunk
                assert len(buffer)<16000
            head,buffer=buffer.split(b'\r\n\r\n',1);status=head.split(b'\r\n',1)[0].decode()
            if status.split(' ')[1] in ('502','503','504'):
                return {'http':status,'event':None,'upstream_starting':True}
            assert '101' in status,status
            def take(size):
                nonlocal buffer
                while len(buffer)<size:
                    chunk=secure.recv(4096);assert chunk,'Conexão encerrada antes do evento';buffer+=chunk
                value,buffer=buffer[:size],buffer[size:];return value
            header=take(2);length=header[1]&127
            if length==126:length=int.from_bytes(take(2),'big')
            elif length==127:length=int.from_bytes(take(8),'big')
            assert length<64000
            event=json.loads(take(length).decode())
            data=event.get('data',{})
            if isinstance(data,str):data=json.loads(data)
            return {'http':status,'event':event.get('event'),'error_code':data.get('code')}
deadline=time.monotonic()+20
while True:
    ws=handshake('https://localhost:8443')
    if not ws.get('upstream_starting'):break
    if time.monotonic()>=deadline:raise AssertionError('O serviço WebSocket não ficou disponível no prazo.')
    time.sleep(0.25)
assert ws['event']=='pusher:connection_established',ws
rejected=handshake('https://untrusted.invalid');assert rejected['event']=='pusher:error' and rejected['error_code']==4009,rejected
print(json.dumps({'ok':True,'websocket':ws,'foreign_origin_rejected':True,'tls_certificate_verified':True,'assets_checked':len(assets),'inertia_prefix':page['url'],'login_redirect':final,'authenticated_bootstrap':True,'secure_cookie_scoped':True,'isolated_database':True}))
