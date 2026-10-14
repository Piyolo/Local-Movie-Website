<#
.SYNOPSIS
  Creates a TV-friendly COPY of one movie file — stereo AAC audio + web-optimized
  MP4 — without ever modifying, moving, or deleting the original.

  Why: many smart-TV browsers cannot decode 5.1 surround AAC audio; playback
  stalls on an endless spinner. This remux produces a copy with a stereo track
  that those browsers can play.

.USAGE
  powershell -ExecutionPolicy Bypass -File tools\make_tv_copy.ps1 "D:\Movies\My Movie (2024)\My Movie.mp4"

  Requires ffmpeg on PATH (winget install Gyan.FFmpeg works) or inside C:\xampp.

.OUTPUT
  A new file next to the original: "My Movie (TV Copy).mp4"
  - Video stream is copied bit-for-bit (no quality loss, very fast).
  - Audio is re-encoded to stereo AAC 192k.
  - Fast-start index written so browsers can begin playing immediately.

.NOTES
  The copy is a normal movie file: rescan the library in Library Settings and it
  appears alongside the original. Archive the original outside the library
  folder yourself if you want only one entry listed. Originals are never touched.
#>
param(
  [Parameter(Mandatory = $true, Position = 0)]
  [string]$VideoPath
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $VideoPath -PathType Leaf)) {
  Write-Error "File not found: $VideoPath"
  exit 1
}

$ffmpeg = $null
foreach ($cand in @('ffmpeg.exe', 'C:\xampp\ffmpeg\bin\ffmpeg.exe', 'C:\xampp\ffmpeg.exe')) {
  if ($cand -eq 'ffmpeg.exe') {
    $cmd = Get-Command ffmpeg -ErrorAction SilentlyContinue
    if ($cmd) { $ffmpeg = $cmd.Source; break }
  } elseif (Test-Path $cand) {
    $ffmpeg = $cand; break
  }
}
if (-not $ffmpeg) {
  Write-Error "ffmpeg not found. Install it (winget install Gyan.FFmpeg) and retry."
  exit 1
}

$item = Get-Item -LiteralPath $VideoPath
$stem = [System.IO.Path]::GetFileNameWithoutExtension($item.Name)
$outDir = $item.DirectoryName
$outPath = Join-Path $outDir ($stem + ' (TV Copy).mp4')

if (Test-Path -LiteralPath $outPath) {
  Write-Error "Output already exists — not overwriting: $outPath"
  exit 1
}

Write-Host "Original : $($item.FullName)"
Write-Host "Creating : $outPath"
Write-Host "Video is copied unchanged; audio becomes stereo AAC. Please wait..."

& $ffmpeg -y -i $item.FullName `
  -map 0:v:0 -map 0:a:0 `
  -c:v copy -c:a aac -ac 2 -b:a 192k -ar 48000 `
  -movflags +faststart `
  -metadata "title=$stem" `
  $outPath

if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $outPath)) {
  Write-Error "ffmpeg failed (exit $LASTEXITCODE). Original untouched."
  exit 1
}

$newSize = (Get-Item -LiteralPath $outPath).Length
Write-Host ("Done. TV copy: {0:N1} MB (original {1:N1} MB, untouched)." -f ($newSize / 1MB), ($item.Length / 1MB))
Write-Host 'Rescan the library in Library Settings to pick it up.'
