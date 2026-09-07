from pathlib import Path

path = Path('app/Services/AutoDownloadService.php')
text = path.read_text()
old = "'search_provider' => $serie->searchProvider ? \" ({$serie->searchProvider})\" : '',"
new = "'search_provider' => $serie->searchProvider !== null && $serie->searchProvider !== '' ? \" ({$serie->searchProvider})\" : '',"

if text.count(old) != 1:
    raise SystemExit(f'expected one searchProvider truthy comparison, found {text.count(old)}')

path.write_text(text.replace(old, new))
