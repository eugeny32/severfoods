// Canteen Access System — Service Worker v2.0
const CACHE = 'canteen-v2';
const OFFLINE_ASSETS = [
    '/index.php', '/login.php',
    '/assets/css/style.css', '/assets/js/app.js',
];
self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(OFFLINE_ASSETS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
    e.waitUntil(caches.keys().then(keys =>
        Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))
    ).then(()=>self.clients.claim()));
});
self.addEventListener('fetch', e => {
    if (e.request.method!=='GET') return;
    const url = new URL(e.request.url);
    if (url.pathname.includes('/api/')||url.search) return;
    e.respondWith(
        fetch(e.request).then(r=>{
            if(r&&r.status===200&&r.type==='basic'){
                caches.open(CACHE).then(c=>c.put(e.request,r.clone()));
            }
            return r;
        }).catch(()=>caches.match(e.request))
    );
});
