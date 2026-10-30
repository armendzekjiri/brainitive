/**
 * The CLI installers (v1.47.1) live on the GitHub release next to the binaries they download, so
 * the install works whatever version the website runs. brainitive.app/install.sh forwards there.
 */
export const CLI_RELEASES_URL = 'https://github.com/armendzekjiri/brainitive/releases/latest/download';

export const CLI_INSTALL_SH = `curl -fsSL ${CLI_RELEASES_URL}/install.sh | sh`;

export const CLI_INSTALL_PS1 = `irm ${CLI_RELEASES_URL}/install.ps1 | iex`;
