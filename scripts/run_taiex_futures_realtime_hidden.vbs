Option Explicit

Dim shell
Dim scriptDirectory
Dim batchPath

scriptDirectory = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
batchPath = scriptDirectory & "\run_taiex_futures_realtime.bat"

Set shell = CreateObject("WScript.Shell")
shell.Run "cmd.exe /d /c """ & batchPath & """", 0, False
