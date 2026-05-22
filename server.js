const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = 3000;

const server = http.createServer((req, res) => {
  console.log(`${req.method} ${req.url}`);

  // Handle API routes
  if (req.url.startsWith('/api/')) {
    const apiName = req.url.split('?')[0].replace('/api/', '');
    // Vercel routes `/api/proxy` to `api/proxy.js`
    const apiPath = path.join(__dirname, 'api', apiName + '.js');

    if (!fs.existsSync(apiPath)) {
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'API not found' }));
      return;
    }

    // Read request body
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      console.log('Request Headers:', req.headers);
      console.log('Request Body Raw:', body);
      // Parse body if JSON
      let parsedBody = {};
      if (req.headers['content-type'] && req.headers['content-type'].includes('application/json') && body) {
        try {
          parsedBody = JSON.parse(body);
          console.log('Parsed Body:', parsedBody);
        } catch (e) {
          console.error('Error parsing JSON body:', e.message);
        }
      }

      // Mock Vercel response helper
      const resMock = {
        statusCode: 200,
        headers: {},
        setHeader(name, val) {
          this.headers[name] = val;
          res.setHeader(name, val);
          return this;
        },
        status(code) {
          this.statusCode = code;
          res.statusCode = code;
          return this;
        },
        json(data) {
          console.log('Sending JSON response:', this.statusCode, JSON.stringify(data).substring(0, 300) + '...');
          res.writeHead(this.statusCode, { 'Content-Type': 'application/json', ...this.headers });
          res.end(JSON.stringify(data));
          return this;
        },
        end(data) {
          console.log('Sending END response:', this.statusCode);
          res.writeHead(this.statusCode, this.headers);
          res.end(data);
          return this;
        }
      };

      const reqMock = {
        method: req.method,
        url: req.url,
        headers: req.headers,
        body: parsedBody
      };

      try {
        // Clear require cache to allow hot reloading during dev
        delete require.cache[require.resolve(apiPath)];
        const handler = require(apiPath);
        handler(reqMock, resMock);
      } catch (err) {
        console.error('API Error:', err);
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: err.message }));
      }
    });
    return;
  }

  // Handle Static files
  let filePath = path.join(__dirname, 'public', req.url === '/' ? 'index.html' : req.url.split('?')[0]);
  if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
    filePath = path.join(__dirname, 'public', 'index.html');
  }

  const ext = path.extname(filePath);
  const mimeTypes = {
    '.html': 'text/html',
    '.css': 'text/css',
    '.js': 'text/javascript',
    '.json': 'application/json',
    '.png': 'image/png',
    '.jpg': 'image/jpg',
    '.gif': 'image/gif',
    '.svg': 'image/svg+xml'
  };

  res.writeHead(200, { 'Content-Type': mimeTypes[ext] || 'text/plain' });
  fs.createReadStream(filePath).pipe(res);
});

server.listen(PORT, () => {
  console.log(`Local dev server running at http://localhost:${PORT}`);
});
