#!/bin/zsh
# Serve only this concept folder so YouTube receives a valid page referrer.
cd -- "${0:A:h}" || exit 1
exec python3 - <<'PY'
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import errno
import sys
from urllib.request import urlopen
import webbrowser

url = "http://localhost:4187/theme-preview.html"
handler = partial(SimpleHTTPRequestHandler, directory=str(Path.cwd()))
try:
    server = ThreadingHTTPServer(("127.0.0.1", 4187), handler)
except OSError as error:
    if error.errno == errno.EADDRINUSE:
        try:
            with urlopen(url, timeout=2) as response:
                if b"Arche Radio" in response.read(4096):
                    webbrowser.open(url)
                    sys.exit(0)
        except OSError:
            pass
    print(f"Die Vorschau konnte nicht gestartet werden: {error}")
    print(f"Falls sie bereits läuft, öffne {url}")
    sys.exit(1)

print(f"Arche Radio Theme-Vorschau: {url}")
print("Dieses Fenster offen lassen. Mit Ctrl+C beenden.")
webbrowser.open(url)
try:
    server.serve_forever()
except KeyboardInterrupt:
    pass
finally:
    server.server_close()
PY
