// A TLS front for the WordPress container, as a host's load balancer would be: HTTPS on 443 with
// a certificate of the local CA, forwarded to WordPress with X-Forwarded-Proto: https.
// Usage: node e2e/tls-proxy.mjs cert.pem key.pem [target, default 127.0.0.1:18080]
import { createServer } from 'node:https';
import { request } from 'node:http';
import { readFileSync } from 'node:fs';

const [cert, key, target = '127.0.0.1:18080'] = process.argv.slice(2);
const [host, port] = target.split(':');
createServer({ cert: readFileSync(cert), key: readFileSync(key) }, (incoming, outgoing) => {
  const forwarded = request({ host, port, method: incoming.method, path: incoming.url, headers: { ...incoming.headers, 'x-forwarded-proto': 'https' } }, (answer) => {
    outgoing.writeHead(answer.statusCode, answer.headers);
    answer.pipe(outgoing);
  });
  forwarded.on('error', () => { outgoing.writeHead(502); outgoing.end(); });
  incoming.pipe(forwarded);
}).listen(443, () => console.log(`https://:443 -> http://${target}`));
