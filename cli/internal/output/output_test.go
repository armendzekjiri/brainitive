package output

import (
	"bytes"
	"strings"
	"testing"
)

func TestTableAlignsAndClips(t *testing.T) {
	var buf bytes.Buffer
	p := &Printer{Out: &buf, Err: &buf}
	p.Table([]string{"key", "title"}, [][]string{
		{"SHOP-1", "Short"},
		{"SHOP-12", "A title with\na newline\tand a tab"},
		{"SHOP-3", strings.Repeat("x", 100)},
	})
	out := buf.String()
	lines := strings.Split(strings.TrimRight(out, "\n"), "\n")
	if len(lines) != 4 || !strings.HasPrefix(lines[0], "KEY") {
		t.Fatalf("unexpected table:\n%s", out)
	}
	if strings.Contains(out, "\n a newline") || strings.Contains(lines[2], "\t") {
		t.Fatalf("newlines / tabs must be flattened:\n%s", out)
	}
	if !strings.Contains(lines[3], "…") {
		t.Fatalf("long cells must be clipped:\n%s", out)
	}
	// Columns line up: "title" starts at the same offset on every row.
	col := strings.Index(lines[0], "TITLE")
	for _, l := range lines[1:] {
		if len(l) <= col || l[col-1] != ' ' {
			t.Fatalf("misaligned row %q", l)
		}
	}
}

func TestTableEmpty(t *testing.T) {
	var buf bytes.Buffer
	p := &Printer{Out: &buf, Err: &buf}
	p.Table([]string{"a"}, nil)
	if strings.TrimSpace(buf.String()) != "(none)" {
		t.Fatalf("got %q", buf.String())
	}
}

func TestValueHelpers(t *testing.T) {
	if Str(float64(3)) != "3" || Str(2.5) != "2.5" || Str(true) != "yes" || Str(nil) != "" || Str("x") != "x" {
		t.Fatal("Str")
	}
	if Int(float64(7)) != 7 || Int("8") != 8 || Int(nil) != 0 {
		t.Fatal("Int")
	}
	rows := List([]any{map[string]any{"a": 1}, "junk", map[string]any{"b": 2}})
	if len(rows) != 2 {
		t.Fatalf("List kept %d rows", len(rows))
	}
	if Clip("héllo", 3) != "hé…" {
		t.Fatalf("Clip = %q", Clip("héllo", 3))
	}
}

func TestLineSilentInJSONMode(t *testing.T) {
	var buf bytes.Buffer
	p := &Printer{JSON: true, Out: &buf, Err: &buf}
	p.Line("hello")
	if buf.Len() != 0 {
		t.Fatal("Line must print nothing in JSON mode")
	}
	p.JSONValue(map[string]any{"a": "<b>"})
	if !strings.Contains(buf.String(), "<b>") {
		t.Fatalf("JSON must not escape HTML: %s", buf.String())
	}
}
