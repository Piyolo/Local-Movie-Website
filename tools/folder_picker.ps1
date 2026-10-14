<#
.SYNOPSIS
  Opens the Windows folder-picker dialog and prints the chosen path as JSON.

  Called by MovieWebsite's api/library.php (action=native_pick) as a FIXED
  command with NO arguments from the request — there is no injection surface
  here by construction. Only the host machine (localhost) can trigger it,
  behind the administrator session + CSRF check in the API.

.OUTPUT (single JSON line on stdout)
  {"path":"D:\\Movies"}                user picked a folder
  {"path":"","cancelled":true}         user pressed Cancel
  {"error":"description"}              dialog or environment failure

.USAGE (normally never run by hand)
  powershell -NoProfile -ExecutionPolicy Bypass -STA -File folder_picker.ps1
  powershell -File folder_picker.ps1 -SelfTest    # no dialog; verifies the
                                                  # COM interop compiles
#>
param([switch]$SelfTest)

$ErrorActionPreference = 'Stop'

# UTF-8 stdout so non-ASCII paths (e.g. é/ñ folder names) survive the pipe.
try { [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false) } catch { }

function Emit([string]$json) {
    [Console]::Out.WriteLine($json)
    [Console]::Out.Flush()
}

# --- Modern Explorer-style picker (IFileOpenDialog, Win10/11) --------------
# Falls back to the classic FolderBrowserDialog if anything about the COM
# interop fails, so the feature degrades gracefully instead of breaking.
$InteropSource = @'
using System;
using System.Runtime.InteropServices;
using System.Text;

namespace MwPicker {
    [Flags]
    public enum OpenOptions : uint {
        PickFolders      = 0x00000020,
        ForceFileSystem  = 0x00000040
    }

    public enum SIGDN : uint {
        FileSyPath = 0x80058000
    }

    [ComImport, Guid("DC1C5A9C-E88A-4dde-A5A1-60F82A20AEF7")]
    public class FileOpenDialog { }

    [ComImport, Guid("43826d1e-e718-42ee-bc55-a1e261c37bfe")]
    [InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    public interface IShellItem {
        void BindToHandler(IntPtr pbc, ref Guid bhid, ref Guid riid, out IntPtr ppv);
        void GetParent(out IShellItem ppsi);
        void GetDisplayName(SIGDN sigdnName, out IntPtr ppszName);
        void GetAttributes(uint sfgaoMask, out uint psfgaoAttribs);
        void Compare(IShellItem psi, uint hint, out int piOrder);
    }

    [ComImport, Guid("d57c7288-d4ad-4768-be02-9d969532d960")]
    [InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    public interface IFileOpenDialog {
        [PreserveSig] int Show(IntPtr hwndParent);
        void SetFileTypes(uint cFileTypes, IntPtr rgFilterSpec);
        void SetFileTypeIndex(uint iFileType);
        void GetFileTypeIndex(out uint piFileType);
        void Advise(IntPtr pfde, out uint pdwCookie);
        void Unadvise(uint dwCookie);
        void SetOptions(OpenOptions fos);
        void GetOptions(out OpenOptions pfos);
        void SetDefaultFolder(IShellItem psi);
        void SetFolder(IShellItem psi);
        void GetFolder(out IShellItem ppsi);
        void GetCurrentSelection(out IShellItem ppsi);
        void SetFileName([MarshalAs(UnmanagedType.LPWStr)] string pszName);
        void GetFileName(StringBuilder pszName);
        void SetTitle([MarshalAs(UnmanagedType.LPWStr)] string pszTitle);
        void SetOkButtonLabel([MarshalAs(UnmanagedType.LPWStr)] string pszText);
        void SetFileNameLabel([MarshalAs(UnmanagedType.LPWStr)] string pszLabel);
        void GetResult(out IShellItem ppsi);
        void AddPlace(IShellItem psi, int fdap);
        void SetDefaultExtension([MarshalAs(UnmanagedType.LPWStr)] string pszDefaultExtension);
        void Close(int hr);
        void SetClientGuid(Guid guid);
        void ClearClientData();
        void SetFilter(IntPtr pFilter);
        void GetResults(out IntPtr ppenum);
        void GetSelectedItems(out IntPtr ppsai);
    }

    public static class Runner {
        // ERROR_CANCELLED — user pressed Cancel/Close in the dialog.
        public const int Cancelled = unchecked((int)0x800704C7);

        public static int ShowFolderPicker(StringBuilder outPath) {
            IFileOpenDialog dlg = (IFileOpenDialog)new FileOpenDialog();
            try {
                dlg.SetOptions(OpenOptions.PickFolders | OpenOptions.ForceFileSystem);
                dlg.SetTitle("Select your movie library folder");
                int hr = dlg.Show(IntPtr.Zero);
                if (hr != 0) return hr;
                IShellItem item;
                dlg.GetResult(out item);
                try {
                    IntPtr pth;
                    item.GetDisplayName(SIGDN.FileSyPath, out pth);
                    try { outPath.Append(Marshal.PtrToStringUni(pth)); }
                    finally { Marshal.FreeCoTaskMem(pth); }
                } finally {
                    Marshal.ReleaseComObject(item);
                }
                return 0;
            } finally {
                Marshal.ReleaseComObject(dlg);
            }
        }
    }
}
'@

function Get-ModernFolder {
    Add-Type -TypeDefinition $InteropSource -ErrorAction Stop | Out-Null
    $sb = New-Object System.Text.StringBuilder 1024
    $hr = [MwPicker.Runner]::ShowFolderPicker($sb)
    if ($hr -eq [MwPicker.Runner]::Cancelled) { return @{ cancelled = $true } }
    if ($hr -ne 0) { throw ('Dialog failed with HRESULT 0x{0:X8}' -f $hr) }
    $path = $sb.ToString()
    if ([string]::IsNullOrWhiteSpace($path)) { return @{ cancelled = $true } }
    return @{ path = $path }
}

function Get-ClassicFolder {
    Add-Type -AssemblyName System.Windows.Forms -ErrorAction Stop | Out-Null
    $dlg = New-Object System.Windows.Forms.FolderBrowserDialog
    $dlg.Description = 'Select your movie library folder'
    $dlg.ShowNewFolderButton = $true
    $result = $dlg.ShowDialog()
    if ($result -eq [System.Windows.Forms.DialogResult]::OK -and $dlg.SelectedPath) {
        return @{ path = $dlg.SelectedPath }
    }
    return @{ cancelled = $true }
}

try {
    if ($SelfTest) {
        # Compile the COM interop only — no dialog shown. Used by automated tests.
        Add-Type -TypeDefinition $InteropSource -ErrorAction Stop | Out-Null
        Emit ((@{ selftest = 'ok'; runtime = [string][System.Environment]::Version }) | ConvertTo-Json -Compress)
        exit 0
    }

    $picked = $null
    try {
        $picked = Get-ModernFolder
    } catch {
        # COM interop unavailable or broken in this session → classic dialog.
        $picked = Get-ClassicFolder
    }

    if ($picked.cancelled) {
        Emit ((@{ path = ''; cancelled = $true }) | ConvertTo-Json -Compress)
    } else {
        Emit ((@{ path = [string]$picked.path }) | ConvertTo-Json -Compress)
    }
    exit 0
} catch {
    $msg = ($_.Exception.Message -replace '[\r\n]', ' ')
    Emit ((@{ error = $msg }) | ConvertTo-Json -Compress)
    exit 0
}
