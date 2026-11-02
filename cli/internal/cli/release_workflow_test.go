package cli

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Codex CLI/extension review 10: checksums.txt sits on the same (replaceable) release as the
// binaries, so it only proves the download wasn't corrupted. Each published artifact also gets a
// Sigstore-signed build-provenance attestation, which lives outside the release (GitHub's
// attestation store + the public transparency log) and names the workflow that built it; a
// swapped asset has no attestation (`gh attestation verify`). This test keeps that in the workflow.
func TestReleaseWorkflowAttestsEveryArtifact(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("..", "..", "..", ".github", "workflows", "release.yml"))
	if err != nil {
		t.Skip("release workflow not in this checkout:", err)
	}
	jobs := splitJobs(string(raw))
	for job, subjects := range map[string][]string{
		"cli":       {"cli/dist/brainitive_*", "cli/dist/checksums.txt", "cli/dist/install.sh", "cli/dist/install.ps1"},
		"extension": {"browser-extension/brainitive-extension-chrome.zip", "browser-extension/brainitive-extension-firefox.zip"},
	} {
		body, ok := jobs[job]
		if !ok {
			t.Fatalf("release.yml has no %q job", job)
		}
		for _, want := range []string{"id-token: write", "attestations: write", "contents: write", "actions/attest-build-provenance@"} {
			if !strings.Contains(body, want) {
				t.Errorf("job %s: missing %q", job, want)
			}
		}
		for _, subject := range subjects {
			if !strings.Contains(body, subject) {
				t.Errorf("job %s: %s is not attested", job, subject)
			}
		}
		// The attestation must be made before the assets are published.
		if a, u := strings.Index(body, "attest-build-provenance"), strings.Index(body, "gh release upload"); a < 0 || u < 0 || a > u {
			t.Errorf("job %s: attest before `gh release upload`", job)
		}
	}
}

// splitJobs maps each top-level job name of a workflow to its text (two-space indented keys).
func splitJobs(workflow string) map[string]string {
	jobs := map[string]string{}
	inJobs, current := false, ""
	for _, line := range strings.Split(workflow, "\n") {
		if strings.HasPrefix(line, "jobs:") {
			inJobs = true
			continue
		}
		if !inJobs {
			continue
		}
		if len(line) > 2 && line[0] == ' ' && line[1] == ' ' && line[2] != ' ' && line[2] != '#' && strings.HasSuffix(strings.TrimSpace(line), ":") {
			current = strings.TrimSuffix(strings.TrimSpace(line), ":")
			continue
		}
		if current != "" {
			jobs[current] += line + "\n"
		}
	}
	return jobs
}
