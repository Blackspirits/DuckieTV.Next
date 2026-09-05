from pathlib import Path

poll = Path('tests/Frontend/PollingReliability.mjs')
text = poll.read_text()
old = "await Promise.resolve();\nawait Promise.resolve();\nawait Promise.resolve();\n"
new = "await new Promise((resolve) => setImmediate(resolve));\n"
if text.count(old) != 1:
    raise SystemExit(f'PollingReliability.mjs: expected async flush marker once, found {text.count(old)}')
poll.write_text(text.replace(old, new, 1))

qb_test = Path('tests/Unit/Services/QBittorrentSessionReuseTest.php')
text = qb_test.read_text()
marker = "use Illuminate\\Support\\Facades\\Http;\n\n"
replacement = marker + "uses(Tests\\TestCase::class);\n\n"
if text.count(marker) != 1:
    raise SystemExit(f'QBittorrentSessionReuseTest.php: expected import marker once, found {text.count(marker)}')
qb_test.write_text(text.replace(marker, replacement, 1))

qb_client = Path('app/Services/TorrentClients/QBittorrentClient.php')
text = qb_client.read_text()
old = "use App\\Services\\SettingsService;\nuse Illuminate\\Contracts\\Encryption\\DecryptException;\nuse Illuminate\\Support\\Facades\\Cache;\nuse Illuminate\\Support\\Facades\\Crypt;\nuse Exception;\n"
new = "use App\\Services\\SettingsService;\nuse Exception;\nuse Illuminate\\Contracts\\Encryption\\DecryptException;\nuse Illuminate\\Support\\Facades\\Cache;\nuse Illuminate\\Support\\Facades\\Crypt;\n"
if text.count(old) != 1:
    raise SystemExit(f'QBittorrentClient.php: expected import block once, found {text.count(old)}')
qb_client.write_text(text.replace(old, new, 1))
