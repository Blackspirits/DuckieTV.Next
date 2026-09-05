from pathlib import Path

poll = Path('tests/Frontend/PollingReliability.mjs')
text = poll.read_text()
old = "await Promise.resolve();\nawait Promise.resolve();\nawait Promise.resolve();\n"
new = "await new Promise((resolve) => setImmediate(resolve));\n"
if text.count(old) != 1:
    raise SystemExit(f'PollingReliability.mjs: expected async flush marker once, found {text.count(old)}')
poll.write_text(text.replace(old, new, 1))

qb = Path('tests/Unit/Services/QBittorrentSessionReuseTest.php')
text = qb.read_text()
marker = "use Illuminate\\Support\\Facades\\Http;\n\n"
replacement = marker + "uses(Tests\\TestCase::class);\n\n"
if text.count(marker) != 1:
    raise SystemExit(f'QBittorrentSessionReuseTest.php: expected import marker once, found {text.count(marker)}')
qb.write_text(text.replace(marker, replacement, 1))
