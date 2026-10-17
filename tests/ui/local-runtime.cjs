// Optional local PHP 8.3 WASM server for synthetic browser tests, not production.
const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {PHP,PHPRequestHandler}=require('../../.cache/ui-tools/node_modules/@php-wasm/universal');
const {loadNodeRuntime,createNodeFsMountHandler}=require('../../.cache/ui-tools/node_modules/@php-wasm/node');
const repo=path.resolve(__dirname,'../..');
const root=path.resolve(repo,process.env.UI_FIXTURE_ROOT||'.cache/site');
if(!root.startsWith(path.join(repo,'.cache')+path.sep))throw new Error('A copied fixture site in .cache/ is required');
const port=Number(process.env.PORT||8080);const wasmDir=path.join(repo,'.cache/ui-tools/node_modules/@php-wasm/node-8-3/asyncify');let nextPid=1000;
async function makePHP(){
 const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:nextPid++,locateFile:p=>path.isAbsolute(p)?p:path.join(wasmDir,p)}}));
 await php.mount('/www',createNodeFsMountHandler(root));
 php.writeFile('/internal/shared/php.ini','memory_limit=256M\nmax_execution_time=60\ndisplay_errors=Off\nlog_errors=On\nsession.save_path=/www/.sessions\n');
 return php;
}
(async()=>{
 const handler=new PHPRequestHandler({documentRoot:'/www',absoluteUrl:`http://127.0.0.1:${port}`,cookieStore:false,maxPhpInstances:1,phpFactory:makePHP});
 await handler.getPrimaryPhp();
 const server=http.createServer(async(req,res)=>{
  try{
   const chunks=[];for await(const chunk of req)chunks.push(chunk);
   const headers=Object.fromEntries(Object.entries(req.headers).map(([key,value])=>[key,Array.isArray(value)?value.join(','):value]));
   const scheme=(headers['x-forwarded-proto']||'').split(',')[0].trim()==='https'?'https':'http';
   const requestUrl=new URL(req.url,`${scheme}://${headers.host||'127.0.0.1:'+port}`).href;
   const response=await handler.request({url:requestUrl,method:req.method,headers,body:chunks.length?new Uint8Array(Buffer.concat(chunks)):undefined});
   res.writeHead(response.httpStatusCode,response.headers);res.end(Buffer.from(response.bytes));
   if(response.errors)console.error(req.method,req.url,response.errors);
  }catch(error){console.error(error);res.writeHead(500,{'Content-Type':'text/plain'});res.end('Local fixture runtime failed');}
 });
 server.listen(port,'0.0.0.0',()=>console.log(`Isolated PHP 8.3 fixture site listening on 0.0.0.0:${port}`));
 process.on('SIGTERM',()=>server.close(()=>process.exit(0)));
})().catch(error=>{console.error(error);process.exit(1)});
