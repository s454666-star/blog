Option Explicit

Dim fileSystem, shell, scriptDirectory, monitorPath, command
Set fileSystem = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

scriptDirectory = fileSystem.GetParentFolderName(WScript.ScriptFullName)
monitorPath = fileSystem.BuildPath(scriptDirectory, "monitor-local-blog.ps1")
command = "powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File """ & monitorPath & """ -CheckIntervalSeconds 10"

shell.Run command, 0, False
