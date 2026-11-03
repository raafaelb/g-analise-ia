#!/usr/bin/env python3
"""Prepara configuração e certificado exclusivos do ensaio HTTPS local."""
from pathlib import Path
import base64,secrets,subprocess,os
root=Path(__file__).resolve().parent.parent
target=root/'backups/homologacao-local'
target.mkdir(parents=True,exist_ok=True,mode=0o700)
env=root/'.env.homologacao'
if not env.exists():
    text=(root/'.env.production.example').read_text().replace('suite.inovadora.com.br','localhost').replace('https://localhost/','https://localhost:8443/')
    for key,value in {'APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'DB_PASSWORD':secrets.token_urlsafe(32),'REVERB_APP_KEY':'homologacao-local','REVERB_APP_SECRET':secrets.token_urlsafe(32)}.items():
        text='\n'.join(key+'='+value if line.split('=',1)[0]==key else line for line in text.split('\n'))
    env.write_text(text.replace('LUMO_LOGIN_ENABLED=true','LUMO_LOGIN_ENABLED=false'));env.chmod(0o600)
text=env.read_text()
missing={name:str(value) for name,value in {'LOCAL_UID':os.getuid(),'LOCAL_GID':os.getgid()}.items() if not any(line.startswith(name+'=') for line in text.splitlines())}
if missing:
    env.write_text(text.rstrip()+'\n'+'\n'.join(name+'='+value for name,value in missing.items())+'\n')
    env.chmod(0o600)
cert=target/'localhost.crt';key=target/'localhost.key'
valid=cert.exists() and key.exists() and subprocess.run(['openssl','x509','-in',str(cert),'-checkend','86400','-noout'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0
if not valid:
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','7','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    key.chmod(0o600)
(target/'tls-nginx.conf').write_text("""resolver 127.0.0.11 valid=5s ipv6=off;
resolver_timeout 2s;
upstream ganalise_test_web {
zone ganalise_test_web 64k;
server web:8080 resolve;
}
server {
listen 8443 ssl;
server_name localhost;
access_log off;
error_log /dev/stderr crit;
ssl_certificate /certs/localhost.crt;
ssl_certificate_key /certs/localhost.key;
location = /ia-ganalise { return 308 /ia-ganalise/; }
location /ia-ganalise/ {
proxy_pass http://ganalise_test_web/;
proxy_set_header Host $http_host;
proxy_set_header X-Forwarded-For $remote_addr;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Port $server_port;
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection \"upgrade\";
proxy_read_timeout 200s;
client_max_body_size 11m;
}
}
""")
print('Configuração de ensaio criada. O certificado local não é instalado no navegador.')
