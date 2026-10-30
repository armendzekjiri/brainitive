// Package output renders results for people (aligned tables, one-line confirmations) or
// for scripts (--json: the tool's structured content, verbatim).
package output

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"
	"text/tabwriter"
	"unicode/utf8"
)

// Printer knows whether the person asked for JSON.
type Printer struct {
	JSON bool
	Out  io.Writer
	Err  io.Writer
}

// New builds a printer for stdout / stderr.
func New(jsonMode bool) *Printer {
	return &Printer{JSON: jsonMode, Out: os.Stdout, Err: os.Stderr}
}

// JSONValue prints any value as indented JSON.
func (p *Printer) JSONValue(v any) {
	enc := json.NewEncoder(p.Out)
	enc.SetIndent("", "  ")
	enc.SetEscapeHTML(false)
	_ = enc.Encode(v)
}

// Table prints rows with a header; cells are stringified and long ones cut.
func (p *Printer) Table(header []string, rows [][]string) {
	if len(rows) == 0 {
		fmt.Fprintln(p.Out, "(none)")
		return
	}
	w := tabwriter.NewWriter(p.Out, 0, 4, 2, ' ', 0)
	fmt.Fprintln(w, strings.Join(upper(header), "\t"))
	for _, row := range rows {
		cells := make([]string, len(row))
		for i, c := range row {
			cells[i] = Clip(strings.ReplaceAll(strings.ReplaceAll(c, "\n", " "), "\t", " "), 80)
		}
		fmt.Fprintln(w, strings.Join(cells, "\t"))
	}
	_ = w.Flush()
}

// Line prints one line for people (nothing in JSON mode: the JSON carries the facts).
func (p *Printer) Line(format string, a ...any) {
	if p.JSON {
		return
	}
	fmt.Fprintf(p.Out, format+"\n", a...)
}

// Note prints to stderr (progress, hints) so stdout stays parseable.
func (p *Printer) Note(format string, a ...any) {
	fmt.Fprintf(p.Err, format+"\n", a...)
}

// Clip shortens a string to n runes with an ellipsis.
func Clip(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	runes := []rune(s)
	return string(runes[:n-1]) + "…"
}

func upper(ss []string) []string {
	out := make([]string, len(ss))
	for i, s := range ss {
		out[i] = strings.ToUpper(s)
	}
	return out
}

// Str reads a value of a structured result as text ("" for nil).
func Str(v any) string {
	switch x := v.(type) {
	case nil:
		return ""
	case string:
		return x
	case bool:
		if x {
			return "yes"
		}
		return "no"
	case float64:
		if x == float64(int64(x)) {
			return strconv.FormatInt(int64(x), 10)
		}
		return strconv.FormatFloat(x, 'f', -1, 64)
	case json.Number:
		return x.String()
	default:
		b, _ := json.Marshal(x)
		return string(b)
	}
}

// Int reads a number of a structured result.
func Int(v any) int64 {
	switch x := v.(type) {
	case float64:
		return int64(x)
	case int64:
		return x
	case int:
		return int64(x)
	case json.Number:
		n, _ := x.Int64()
		return n
	case string:
		n, _ := strconv.ParseInt(x, 10, 64)
		return n
	}
	return 0
}

// List reads an array of objects from a structured result.
func List(v any) []map[string]any {
	items, _ := v.([]any)
	out := make([]map[string]any, 0, len(items))
	for _, it := range items {
		if m, ok := it.(map[string]any); ok {
			out = append(out, m)
		}
	}
	return out
}

// Map reads a nested object.
func Map(v any) map[string]any {
	m, _ := v.(map[string]any)
	return m
}
