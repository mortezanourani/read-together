const CACHE_NAME = "read-together-shell-v16";
const APP_SHELL = [
    "./offline.html",
    "./assets/css/app.css",
    "./assets/js/app.js",
    "./assets/icons/icon-192.svg",
    "./assets/icons/icon-512.svg"
];

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(APP_SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => Promise.all(
                cacheNames
                    .filter((cacheName) => cacheName.startsWith("read-together-shell-")
                        && cacheName !== CACHE_NAME)
                    .map((cacheName) => caches.delete(cacheName))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== "GET" || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === "navigate") {
        event.respondWith(
            fetch(request)
                .catch(() => caches.match("./offline.html"))
        );
        return;
    }

    if (url.pathname.includes("/assets/")) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        event.waitUntil(
                            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy))
                        );
                    }
                    return response;
                })
                .catch(() => caches.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    throw new TypeError("No cached response is available while offline.");
                }))
        );
    }
});
