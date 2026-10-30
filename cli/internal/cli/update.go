package cli

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"github.com/spf13/cobra"
)

// Repo is where releases live.
const Repo = "armendzekjiri/brainitive"

// AssetName is the release asset for a platform: brainitive_<os>_<arch>.tar.gz (zip on Windows).
func AssetName(goos, goarch string) string {
	ext := ".tar.gz"
	if goos == "windows" {
		ext = ".zip"
	}
	return fmt.Sprintf("brainitive_%s_%s%s", goos, goarch, ext)
}

func (a *App) updateCommand() *cobra.Command {
	var check bool
	cmd := &cobra.Command{
		Use:   "update",
		Short: "Update the CLI to the latest release",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx, cancel := context.WithTimeout(cmd.Context(), 5*time.Minute)
			defer cancel()
			latest, err := latestVersion(ctx)
			if err != nil {
				return err
			}
			current := strings.TrimPrefix(a.Version, "v")
			if current == latest {
				a.out.Note("brainitive %s is the latest version.", current)
				return nil
			}
			a.out.Note("Latest: %s (you have %s).", latest, current)
			if check {
				return nil
			}
			exe, err := os.Executable()
			if err != nil {
				return err
			}
			exe, _ = filepath.EvalSymlinks(exe)
			if err := downloadAndReplace(ctx, latest, exe, func(s string) { a.out.Note("%s", s) }); err != nil {
				return err
			}
			a.out.Note("Updated to brainitive %s.", latest)
			return nil
		},
	}
	cmd.Flags().BoolVar(&check, "check", false, "only report whether an update exists")
	return cmd
}

func latestVersion(ctx context.Context) (string, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://api.github.com/repos/"+Repo+"/releases/latest", nil)
	req.Header.Set("Accept", "application/vnd.github+json")
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("couldn't check GitHub for releases: %w", err)
	}
	defer res.Body.Close()
	var out struct {
		TagName string `json:"tag_name"`
	}
	if err := json.NewDecoder(res.Body).Decode(&out); err != nil || out.TagName == "" {
		return "", errors.New("couldn't read the latest release from GitHub")
	}
	return strings.TrimPrefix(out.TagName, "v"), nil
}

func downloadAndReplace(ctx context.Context, version, exe string, status func(string)) error {
	asset := AssetName(runtime.GOOS, runtime.GOARCH)
	base := fmt.Sprintf("https://github.com/%s/releases/download/v%s/", Repo, version)
	status("Downloading " + asset + " …")
	archive, err := fetch(ctx, base+asset)
	if err != nil {
		return err
	}
	sums, err := fetch(ctx, base+"checksums.txt")
	if err != nil {
		return err
	}
	if !checksumMatches(sums, asset, archive) {
		return errors.New("the download doesn't match checksums.txt; not installing it")
	}
	binary, err := extractBinary(archive, asset)
	if err != nil {
		return err
	}
	tmp := exe + ".new"
	if err := os.WriteFile(tmp, binary, 0o755); err != nil {
		return fmt.Errorf("couldn't write next to %s (try with sudo, or reinstall with the install script): %w", exe, err)
	}
	if runtime.GOOS == "windows" {
		// A running .exe can't be overwritten; it can be renamed.
		_ = os.Remove(exe + ".old")
		if err := os.Rename(exe, exe+".old"); err != nil {
			return err
		}
	}
	return os.Rename(tmp, exe)
}

func fetch(ctx context.Context, url string) ([]byte, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: HTTP %d", url, res.StatusCode)
	}
	return io.ReadAll(io.LimitReader(res.Body, 200<<20))
}

// checksumMatches checks a `sha256  name` line of checksums.txt against the archive.
func checksumMatches(sums []byte, asset string, archive []byte) bool {
	sum := sha256.Sum256(archive)
	want := hex.EncodeToString(sum[:])
	for _, line := range strings.Split(string(sums), "\n") {
		fields := strings.Fields(line)
		if len(fields) == 2 && strings.TrimPrefix(fields[1], "*") == asset {
			return strings.EqualFold(fields[0], want)
		}
	}
	return false
}

// extractBinary pulls the `brainitive` executable out of the release archive.
func extractBinary(archive []byte, asset string) ([]byte, error) {
	want := "brainitive"
	if strings.HasSuffix(asset, ".zip") {
		want = "brainitive.exe"
		r, err := zip.NewReader(bytes.NewReader(archive), int64(len(archive)))
		if err != nil {
			return nil, err
		}
		for _, f := range r.File {
			if filepath.Base(f.Name) == want {
				rc, err := f.Open()
				if err != nil {
					return nil, err
				}
				defer rc.Close()
				return io.ReadAll(rc)
			}
		}
		return nil, errors.New("no brainitive.exe in the archive")
	}
	gz, err := gzip.NewReader(bytes.NewReader(archive))
	if err != nil {
		return nil, err
	}
	tr := tar.NewReader(gz)
	for {
		h, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return nil, err
		}
		if h.Typeflag == tar.TypeReg && filepath.Base(h.Name) == want {
			return io.ReadAll(tr)
		}
	}
	return nil, errors.New("no brainitive binary in the archive")
}
