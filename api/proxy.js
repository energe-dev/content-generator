const https = require('https');

const GEMINI_KEY = process.env.GEMINI_KEY || 'AIzaSyB-1oGZOAq2UMlYcniKFyioXVmnQniRxCc';
const MODELS = [
  'gemini-3.5-flash',
  'gemini-3.1-flash-lite',
  'gemini-2.5-flash',
];

function geminiRequest(model, prompt) {
  return new Promise((resolve, reject) => {
    const body = JSON.stringify({
      contents: [{ parts: [{ text: prompt }] }],
      generationConfig: { 
        temperature: 0.8, 
        maxOutputTokens: 8192,
        responseMimeType: 'application/json',
        responseSchema: {
          type: 'OBJECT',
          properties: {
            title: { type: 'STRING' },
            excerpt: { type: 'STRING' },
            body: { type: 'STRING' },
            tags: { 
              type: 'ARRAY', 
              items: { type: 'STRING' } 
            },
            slug: { type: 'STRING' },
            metaDesc: { type: 'STRING' },
            readTime: { type: 'STRING' }
          },
          required: ['title', 'excerpt', 'body', 'tags', 'slug', 'metaDesc', 'readTime']
        }
      }
    });
    const options = {
      hostname: 'generativelanguage.googleapis.com',
      path: `/v1beta/models/${model}:generateContent?key=${GEMINI_KEY}`,
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) }
    };
    const req = https.request(options, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ status: res.statusCode, body: data }));
    });
    req.on('error', reject);
    req.setTimeout(30000, () => { req.destroy(); reject(new Error('timeout')); });
    req.write(body);
    req.end();
  });
}

module.exports = async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ ok: false, error: 'Método no permitido' });

  const { prompt } = req.body || {};
  if (!prompt) return res.status(400).json({ ok: false, error: 'Sin prompt' });

  let lastError = '';
  for (const model of MODELS) {
    try {
      const { status, body } = await geminiRequest(model, prompt);
      const data = JSON.parse(body);
      if (data.error) {
        lastError = `${model}: ${data.error.message}`;
        if ([400, 404, 429].includes(data.error.code)) continue;
        return res.status(200).json({ ok: false, error: data.error.message });
      }
      const text = data.candidates?.[0]?.content?.parts?.[0]?.text;
      if (!text) { lastError = `${model}: respuesta vacía`; continue; }
      return res.status(200).json({ ok: true, text, model });
    } catch (e) {
      lastError = `${model}: ${e.message}`;
      continue;
    }
  }
  return res.status(200).json({ ok: false, error: 'Sin respuesta de Gemini. Último error: ' + lastError });
};
