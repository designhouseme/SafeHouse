"""Serve the landing page without browser caching: python3 dev/serve-site.py [port]"""
import functools
import http.server
import pathlib
import sys


class NoCache(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, *args):
        pass


port = int(sys.argv[1]) if len(sys.argv) > 1 else 8899
site = pathlib.Path(__file__).resolve().parent.parent / "site"
print(f"http://127.0.0.1:{port}/")
http.server.ThreadingHTTPServer(("127.0.0.1", port), functools.partial(NoCache, directory=str(site))).serve_forever()
