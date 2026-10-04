# Spoustec scraperu na NUC. Vola ho Planovac Windows (ulohy AgregatorFull a AgregatorQuick).
# Stahne aktualni kod z GitHubu, spusti scraper.py a vysledne JSONy pushne do repa,
# odkud je ctou stranky na max-reality.cz.
#
# Data:  C:\Users\patri\agregator-data\agregator.sqlite (historie, mimo Syncthing)
# Log:   C:\Users\patri\Nahravky\zpracovano\agregator.log (cte ho tab Stroje), detail posledniho behu v agregator-data
param([ValidateSet("quick", "full")][string]$Mode = "quick")

$data  = "C:\Users\patri\agregator-data"
$repo  = Join-Path $data "repo"
$db    = Join-Path $data "agregator.sqlite"
$lock  = Join-Path $data "bezi.lock"
$log   = "C:\Users\patri\Nahravky\zpracovano\agregator.log"
$beh   = Join-Path $data "posledni-$Mode.log"
$py    = "C:\Python313\python.exe"

function Zapis($m) { "{0} [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Mode, $m | Add-Content -Encoding utf8 $log }

# Full beh trva kolem 1,5 h. Kdyz zrovna bezi, quick se preskoci. Zamek starsi 5 h je pozustatek padu.
if (Test-Path $lock) {
    $stari = (Get-Date) - (Get-Item $lock).LastWriteTime
    if ($stari.TotalHours -lt 5) { Zapis "preskoceno, bezi jiny beh"; exit 0 }
}
Set-Content -Path $lock -Value $PID

try {
    Set-Location $repo
    git pull --rebase --quiet origin main 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { Zapis "CHYBA git pull (kod $LASTEXITCODE)"; exit 1 }

    $env:PYTHONIOENCODING = "utf-8"
    $start = Get-Date
    cmd /c "`"$py`" scraper.py --mode $Mode --db `"$db`" --out `"$repo`" > `"$beh`" 2>&1"
    $kod = $LASTEXITCODE
    $min = [math]::Round(((Get-Date) - $start).TotalMinutes)
    $souhrn = (Get-Content $beh -Encoding utf8 | Where-Object { $_ -match "^(Aktivnich|Feed|Videno)" }) -join " | "

    if ($kod -ne 0) {
        $konec = (Get-Content $beh -Encoding utf8 -Tail 3) -join " / "
        Zapis "CHYBA scraper skoncil kodem $kod po $min min: $konec"
        exit $kod
    }

    git add feed.json archived.json price_history.json market_stats.json
    git diff --staged --quiet
    if ($LASTEXITCODE -ne 0) {
        git commit -q -m ("feed: {0} {1} (NUC)" -f $Mode, (Get-Date -Format "yyyy-MM-dd HH:mm"))
        git pull --rebase --quiet origin main 2>&1 | Out-Null
        git push --quiet origin main 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Zapis "CHYBA git push (kod $LASTEXITCODE), data zustala lokalne"; exit 1 }
    }
    Zapis "OK za $min min. $souhrn"
}
finally {
    Remove-Item $lock -ErrorAction SilentlyContinue
}
