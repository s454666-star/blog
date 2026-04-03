Option Explicit

Function Quote(ByVal value)
    Quote = """" & Replace(value, """", """""") & """"
End Function

Dim shell
Dim command
Dim batchPath
Dim workingDirectory

If WScript.Arguments.Count < 1 Then
    WScript.Quit 1
End If

batchPath = WScript.Arguments(0)
command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File ""C:\www\blog\scripts\run_hidden_task.ps1"" -BatchPath " & Quote(batchPath)

If WScript.Arguments.Count >= 2 Then
    workingDirectory = WScript.Arguments(1)
    If Len(workingDirectory) > 0 Then
        command = command & " -WorkingDirectory " & Quote(workingDirectory)
    End If
End If

Set shell = CreateObject("WScript.Shell")
shell.Run command, 0, False
