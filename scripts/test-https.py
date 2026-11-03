#!/usr/bin/env python3
"""Valida HTTPS e autenticação apenas no banco efêmero de homologação."""
from pathlib import Path
import urllib.request,urllib.parse,urllib.error,http.cookiejar,ssl,re,json,subprocess,secrets,html,socket,base64,time
root=Path(__file__).resolve().parent.parent
compose=['docker','compose','--project-directory',str(root),'--env-file','.env.homologacao','-f','compose.homologacao.yaml']
password=secrets.token_urlsafe(32)
seed='''require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();if(config("database.connections.pgsql.database")!=="ganalise_ensaio_descartavel"||config("app.url")!=="https://localhost:8443/ia-ganalise"||config("horus.openai.enabled")||config("horus.openai.plan_enabled")||config("horus.lumo.login_enabled")||config("suite.enabled")||config("horus.mcp.enabled")||config("horus.viewer.enabled")){exit(3);}$p=json_decode(stream_get_contents(STDIN),true);App\\Models\\User::updateOrCreate(["email"=>"homologacao@example.invalid"],["name"=>"Homologação efêmera","role"=>"owner","password"=>Illuminate\\Support\\Facades\\Hash::make($p["password"])]);echo "seed_ok";'''
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
error_pages={}
for name,path,method,payload,headers,expected_status in [
    ('missing','/missing-recovery-path?view=ready','GET',None,{'Accept':'text/html'},404),
    ('expired','/login','POST',b'{}',{'Accept':'text/html','Content-Type':'application/json','X-Inertia':'true','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':'synthetic-invalid','Origin':'https://localhost:8443'},419),
]:
    try:
        failure=urllib.request.urlopen(urllib.request.Request(base+path,method=method,data=payload,headers=headers),context=ctx,timeout=10)
    except urllib.error.HTTPError as failure_response:
        failure=failure_response
    with failure:
        failure_body=failure.read().decode()
        assert failure.status==expected_status and failure.headers.get('Location') is None,name
        if 'application/json' in failure.headers.get('Content-Type',''):
            failure_page=json.loads(failure_body)
        else:
            failure_page=json.loads(re.search(r'<script data-page="app" type="application/json">(.*?)</script>',failure_body,re.S)[1])
    assert failure_page['component']=='Error' and failure_page['props']['status']==expected_status,name
    assert failure_page['url']=='/ia-ganalise'+path,name
    error_pages[name]={'status':expected_status,'public_prefix_preserved':True,'no_redirect':True}
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
auth_marker='synthetic-auth-return-'+secrets.token_urlsafe(24)
request=urllib.request.Request(base+'/auth/openai/callback?'+urllib.parse.urlencode({'code':auth_marker,'state':auth_marker+'-state'}),headers={'Authorization':'Bearer '+auth_marker,'Referer':'https://example.invalid/?code='+auth_marker})
with opener.open(request,timeout=10) as response:
    assert response.geturl()==base+'/app/connections','O retorno de autenticação saiu do subdiretório.'
deadline=time.monotonic()+3
while True:
    captured=subprocess.run(compose+['logs','--no-color','app','web','tls-test'],capture_output=True,text=True,check=True)
    container_logs=captured.stdout+captured.stderr
    assert auth_marker not in container_logs and password not in container_logs,'Dados fictícios de autenticação apareceram nos logs do contêiner.'
    if '"path":"/auth/openai/callback"' in container_logs:break
    assert time.monotonic()<deadline,'O acesso ao callback não apareceu no log HTTP esperado.'
    time.sleep(0.1)
print(json.dumps({'ok':True,'websocket':ws,'foreign_origin_rejected':True,'tls_certificate_verified':True,'assets_checked':len(assets),'inertia_prefix':page['url'],'login_redirect':final,'authenticated_bootstrap':True,'secure_cookie_scoped':True,'isolated_database':True,'auth_return_prefix':True,'synthetic_auth_data_absent_from_logs':True,'error_pages':error_pages}))
