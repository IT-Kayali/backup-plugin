import json, os, pathlib, subprocess, urllib.request, urllib.error, socket, time
repo=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
ext=os.environ.get('PHP_EXTENSION_DIR','')
args=[php,'-n']+(['-d','extension_dir='+ext] if ext else [])+['-d','extension=zip.so']
output=subprocess.check_output(args+[str(repo/'tests/regressions.php')],text=True)
base=pathlib.Path(output.split('FIXTURE ')[-1].strip()); fixture=json.loads((base/'fixture.json').read_text())
with socket.socket() as sock: sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
origin=f'http://127.0.0.1:{port}'
log=open(base/'server.log','w');proc=subprocess.Popen(args+['-S',f'127.0.0.1:{port}','-t',str(base/'site')],stdout=log,stderr=log)
def req(query='',data=None,origin_header=None):
    from urllib.parse import urlencode
    headers={} if origin_header is None else {'Origin':origin_header}
    r=urllib.request.Request(origin+'/ikb-emergency-restore.php'+query,data=None if data is None else urlencode(data).encode(),headers=headers)
    try:
        with urllib.request.urlopen(r,timeout=5) as v:return v.status,v.read().decode()
    except urllib.error.HTTPError as e:return e.code,e.read().decode()
try:
    for _ in range(30):
        try:req();break
        except OSError:time.sleep(.1)
    assert req()[0]==403;print('PASS emergency without token denied')
    assert req('?token=wrong')[0]==403;print('PASS wrong token denied')
    assert req('?token=synthetic-test-only')[0]==200;print('PASS authenticated GET is non-destructive form')
    values={'token':'synthetic-test-only','backup':pathlib.Path(fixture['snapshot']).name,'mode':'verify'}
    before=(base/'site/wp-content/plugins/it-kayali-translate/extra.js').read_bytes()
    assert req(data=values,origin_header='https://outside.invalid')[0]==403;print('PASS cross-origin POST rejected')
    assert req(data=values)[0]==403;print('PASS missing-origin POST rejected')
    status,body=req(data=values,origin_header=origin)
    assert status==200 and 'VERIFIED:' in body and 'Scope: translate-plugin' in body;print('PASS same-origin verify-only validates plugin snapshot')
    assert (base/'site/wp-content/plugins/it-kayali-translate/extra.js').read_bytes()==before;print('PASS verify-only changes no target files')
    values['mode']='restore'
    status,body=req(data=values,origin_header=origin)
    assert 'Confirmation missing' in body;print('PASS destructive restore requires typed confirmation')
    # Browser plugin-only rollback of a synthetic broken plugin, not a live site.
    (base/'site/wp-content/plugins/it-kayali-translate/extra.js').write_text('broken in synthetic test')
    values['confirm']='RESTORE'
    status,body=req(data=values,origin_header=origin)
    assert status==200 and 'Database statements: 0' in body;print('PASS browser standalone plugin rollback completes without database')
    assert (base/'site/wp-content/plugins/it-kayali-translate/extra.js').read_bytes()==before;print('PASS browser rollback restores original bytes')
    print('HTTP ASSERTIONS 10')
finally:
    proc.terminate();proc.wait(timeout=5);log.close()
