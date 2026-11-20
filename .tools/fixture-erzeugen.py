"""Erzeugt .tools/fixture-srp.json und .tools/fixture-bplist.json mit pyatv-Bibliotheken.
Aufruf: <venv mit pyatv>/bin/python -I .tools/fixture-erzeugen.py <Ausgabeordner>"""
import base64, binascii, hashlib, json, os, plistlib, sys
from srptools import SRPClientSession, SRPContext, constants

ziel = sys.argv[1]
faelle = []
for i in range(8):
    a = os.urandom(32)
    salz = (b"\x00" if i % 2 else b"") + os.urandom(16 - (i % 2))
    b = (b"\x00" if i >= 4 else b"") + os.urandom(384 - (1 if i >= 4 else 0))
    ctx = SRPContext("Pair-Setup", "3939", prime=constants.PRIME_3072, generator=constants.PRIME_3072_GEN, hash_func=hashlib.sha512)
    s = SRPClientSession(ctx, binascii.hexlify(a).decode())
    s.process(binascii.hexlify(b).decode(), binascii.hexlify(salz).decode())
    k = s.key.decode() if isinstance(s.key, bytes) else s.key
    m = s.key_proof.decode() if isinstance(s.key_proof, bytes) else s.key_proof
    faelle.append({"a": a.hex(), "salz": salz.hex(), "b": b.hex(), "A": s.public, "K": k, "M": m})
json.dump(faelle, open(os.path.join(ziel, "fixture-srp.json"), "w"), indent=1)

wert = {"streams": [{"audioFormat": 0x800, "ct": 1, "isMedia": True, "shk": bytes(range(32)), "streamConnectionID": 4000000000,
        "name": "Küche", "latencyMax": 88200, "x": 1.5}], "eventPort": 51234, "liste": ["a", "b"] * 10}
json.dump({"bplist": base64.b64encode(plistlib.dumps(wert, fmt=plistlib.FMT_BINARY)).decode()},
          open(os.path.join(ziel, "fixture-bplist.json"), "w"), indent=1)
print("Fixtures geschrieben")
