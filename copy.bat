@echo off
setlocal enabledelayedexpansion

set "tempfile=%temp%\tempclip.txt"
if exist "%tempfile%" del /q "%tempfile%"

for /r %%I in (*) do (
    if /i not "%%~nxI"=="copy.bat" (
        if /i not "%%~xI"==".wav" (
            type "%%I" >> "%tempfile%"
            echo. >> "%tempfile%"
            echo -------- Fin de: %%I -------- >> "%tempfile%"
            echo. >> "%tempfile%"
        )
    )
)

clip < "%tempfile%"
del /q "%tempfile%"
echo Todos los contenidos han sido copiados al portapapeles