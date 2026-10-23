/**
 * Programs and scripts the public helpdesk forms refuse (same list as Uploads::EXECUTABLE on the
 * server, which also checks the file content). Checked when a file is picked, so visitors get a
 * clear message instead of a failed upload.
 */
const BLOCKED = new Set([
    'exe', 'msi', 'msp', 'msix', 'appx', 'com', 'scr', 'pif', 'cpl', 'dll', 'sys', 'lnk', 'reg', 'inf',
    'bat', 'cmd', 'ps1', 'psm1', 'psd1', 'vbs', 'vbe', 'js', 'jse', 'mjs', 'wsf', 'wsh', 'hta', 'msc',
    'jar', 'class', 'apk', 'aab', 'ipa', 'app', 'dmg', 'pkg', 'mpkg', 'command', 'deb', 'rpm', 'appimage', 'run', 'snap',
    'sh', 'bash', 'zsh', 'csh', 'ksh', 'fish', 'py', 'pyc', 'pyw', 'pl', 'rb', 'lua', 'php', 'phtml', 'phar', 'cgi', 'asp', 'aspx', 'jsp',
    'html', 'htm', 'xhtml', 'xhtm', 'svg', 'svgz', 'xpi', 'crx', 'so', 'dylib', 'elf', 'iso', 'img', 'vhd', 'vhdx',
]);

/** True when any extension in the name is a program / script type (invoice.pdf.exe counts). */
export function isBlockedUpload(name: string): boolean {
    return name.toLowerCase().split('.').slice(1).some((ext) => BLOCKED.has(ext.trim()));
}

export const BLOCKED_UPLOAD_MESSAGE = "Programs and scripts can't be attached here.";
