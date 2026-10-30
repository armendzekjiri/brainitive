// The Brainitive CLI: a single static binary that talks to a Brainitive server's MCP endpoint.
// See cli/README.md and https://docs.brainitive.app/integrations/cli.
package main

import (
	"fmt"
	"os"

	"github.com/armendzekjiri/brainitive/cli/internal/cli"
)

// Set by the release build: -ldflags "-X main.version=1.47.0".
var version = "dev"

func main() {
	if err := cli.Execute(version); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}
