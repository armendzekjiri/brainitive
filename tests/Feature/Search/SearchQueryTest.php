<?php

namespace Tests\Feature\Search;

use App\Models\Board;
use App\Models\Capture;
use App\Models\Comment;
use App\Models\Page;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Support\Search\Engines\BasicSearchEngine;
use App\Support\Search\QueryParser;
use App\Support\Search\SearchEngine;
use App\Support\Search\SearchIndexer;
use App\Support\Search\SearchService;
use Illuminate\Support\Facades\DB;

/**
 * Matching, ranking, typo tolerance, filters, highlighting and pagination of the search (BRAI-55).
 */
class SearchQueryTest extends SearchTestCase
{
    public function test_an_exact_key_puts_that_issue_first(): void
    {
        $target = $this->item(['title' => 'Something unrelated']);
        $this->item(['title' => 'Follow-up', 'description' => '<p>Depends on '.$target->key.' and more '.$target->key.' talk</p>']);
        $this->item(['title' => 'Mentions '.$target->key.' in the title']);

        $this->assertSame("item:{$target->id}", $this->ids($this->editor, $target->key)[0]);
        $this->assertSame("item:{$target->id}", $this->ids($this->editor, strtolower($target->key))[0]);
    }

    public function test_title_matches_rank_above_body_matches(): void
    {
        $body = $this->item(['title' => 'Notes', 'description' => '<p>We should look at the pelican later</p>']);
        $title = $this->item(['title' => 'Pelican migration']);
        // the body match is newer: the title still wins
        DB::table('items')->where('id', $title->id)->update(['updated_at' => now()->subYear()]);
        app(SearchIndexer::class)->touch('item', $title->id);

        $this->assertSame(["item:{$title->id}", "item:{$body->id}"], $this->ids($this->editor, 'pelican'));
    }

    public function test_prefix_phrases_and_exclusions(): void
    {
        $a = $this->item(['title' => 'Roadmap for Q3 planning']);
        $b = $this->item(['title' => 'Planning the roadmap review']);

        // the last word is a prefix
        $this->assertEqualsCanonicalizing(["item:{$a->id}", "item:{$b->id}"], $this->ids($this->editor, 'roadm'));
        // a phrase keeps the order
        $this->assertSame(["item:{$a->id}"], $this->ids($this->editor, '"roadmap for"'));
        // -word excludes
        $this->assertSame(["item:{$b->id}"], $this->ids($this->editor, 'roadmap -q3'));
        // exclusions alone find nothing (and don't list everything)
        $this->assertSame(0, $this->search($this->editor, '-roadmap')['total']);
    }

    public function test_accents_case_and_punctuation_do_not_matter(): void
    {
        $item = $this->item(['title' => 'Größe der Übersicht prüfen', 'description' => '<p>see invoice-2024.pdf and e-mail</p>']);

        foreach (['grosse', 'GRÖSSE', 'ubersicht', 'Übersicht', 'invoice', '2024', 'mail', 'invoice-2024'] as $q) {
            $this->assertSame(["item:{$item->id}"], $this->ids($this->editor, $q), "query {$q}");
        }
    }

    public function test_a_typo_still_finds_the_title(): void
    {
        $item = $this->item(['title' => 'Checkout bug on mobile']);

        $found = $this->search($this->editor, 'chekout');
        $this->assertSame(["item:{$item->id}"], array_column($found['results'], 'id'));
        $this->assertTrue($found['fuzzy']);

        // exact matches are not "fuzzy"
        $this->assertFalse($this->search($this->editor, 'checkout')['fuzzy']);
    }

    public function test_filters_type_project_status_assignee_and_updated(): void
    {
        $doneColumn = $this->board->columns()->where('is_done_column', true)->firstOrFail();
        $open = $this->item(['title' => 'Falcon open', 'assigned_to' => $this->editor->id]);
        $done = $this->item(['title' => 'Falcon done', 'column_id' => $doneColumn->id]);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Falcon doc', 'status' => 'published']);
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);
        $otherBoard = Board::factory()->create(['project_id' => $other->id, 'created_by' => $this->owner->id]);
        $elsewhere = $this->item(['board_id' => $otherBoard->id, 'title' => 'Falcon elsewhere']);
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
        $task = TaskListItem::factory()->create(['task_list_id' => $list->id, 'created_by' => $this->owner->id, 'title' => 'Falcon task', 'assignee_id' => $this->editor->id]);

        // type: counts stay for every type, the page shows one
        $found = $this->search($this->owner, 'falcon', ['type' => 'issues']);
        $this->assertSame(3, $found['total']);
        $this->assertSame(['issues' => 3, 'docs' => 1, 'tasks' => 1], array_filter($found['counts']));
        $this->assertCount(3, $found['results']);

        $this->assertSame(["item:{$elsewhere->id}"], $this->ids($this->owner, 'falcon', ['project' => [$other->id]]));
        $this->assertSame(["item:{$done->id}"], $this->ids($this->owner, 'falcon', ['status' => 'done']));
        $this->assertEqualsCanonicalizing(["item:{$open->id}", "item:{$elsewhere->id}", "task:{$task->id}"], $this->ids($this->owner, 'falcon', ['status' => 'open']));
        $this->assertEqualsCanonicalizing(["item:{$open->id}", "task:{$task->id}"], $this->ids($this->editor, 'falcon', ['assignee' => 'me']));
        $this->assertEqualsCanonicalizing(["item:{$open->id}", "task:{$task->id}"], $this->ids($this->owner, 'falcon', ['assignee' => (string) $this->editor->id]));

        DB::table('items')->where('id', $elsewhere->id)->update(['updated_at' => now()->subDays(60)]);
        app(SearchIndexer::class)->touch('item', $elsewhere->id);
        $this->assertNotContains("item:{$elsewhere->id}", $this->ids($this->owner, 'falcon', ['updated' => '30d']));
        $this->assertContains("item:{$elsewhere->id}", $this->ids($this->owner, 'falcon', ['updated' => '90d']));
        $this->assertSame(["item:{$elsewhere->id}"], $this->ids($this->owner, 'falcon', ['to' => now()->subDays(30)->format('Y-m-d')]));

        // a filter on a project the user can't open finds nothing
        $this->assertSame([], $this->ids($this->viewer, 'falcon', ['project' => [$other->id]]));
    }

    public function test_highlights_escape_html_and_only_mark_matches(): void
    {
        $item = $this->item(['title' => '<script>alert(1)</script> Iguana & co', 'description' => '<p>&lt;img src=x onerror=alert(1)&gt; the iguana sleeps</p>']);
        Capture::factory()->create(['user_id' => $this->editor->id, 'text' => "iguana \u{E000}fake\u{E001} <b>bold</b>"]);

        foreach ($this->search($this->editor, 'iguana')['results'] as $result) {
            foreach (['title_html', 'snippet_html'] as $field) {
                $html = $result[$field];
                $this->assertSame([], array_diff(self::tags($html), ['<mark>', '</mark>']), "{$field}: {$html}");
                $this->assertStringNotContainsString("\u{E000}", $html);
            }
            $this->assertStringContainsString('<mark>', $result['title_html'].$result['snippet_html']);
        }

        $issue = collect($this->search($this->editor, 'iguana')['results'])->firstWhere('id', "item:{$item->id}");
        $this->assertStringContainsString('&lt;script&gt;', $issue['title_html']);
        $this->assertStringContainsString('<mark>Iguana</mark>', $issue['title_html']);
        $this->assertStringContainsString('onerror=alert(1)&gt;', $issue['snippet_html']);
    }

    public function test_pages_and_counts_agree(): void
    {
        foreach (range(1, 25) as $n) {
            $this->item(['title' => "Lemur number {$n}"]);
        }

        $first = $this->search($this->editor, 'lemur');
        $this->assertSame(25, $first['total']);
        $this->assertCount(20, $first['results']);
        $this->assertTrue($first['has_more']);

        $second = $this->search($this->editor, 'lemur', ['page' => 2]);
        $this->assertCount(5, $second['results']);
        $this->assertFalse($second['has_more']);
        $this->assertSame([], array_intersect(array_column($first['results'], 'id'), array_column($second['results'], 'id')));
    }

    public function test_the_number_of_queries_does_not_grow_with_results(): void
    {
        $this->item(['title' => 'Otter one']);
        $this->flush();

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->search($this->owner, 'otter');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $few = $count();

        foreach (range(1, 15) as $n) {
            $item = $this->item(['title' => "Otter {$n}"]);
            Comment::create(['item_id' => $item->id, 'user_id' => $this->editor->id, 'body' => "otter comment {$n}"]);
        }
        Page::factory()->count(3)->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Otter doc', 'status' => 'published']);
        $this->flush();

        // more types on the page may add one lookup each, never one per result
        $this->assertLessThanOrEqual($few + 3, $count());
    }

    public function test_the_basic_engine_has_the_same_visibility(): void
    {
        $this->app->singleton(SearchEngine::class, fn () => new BasicSearchEngine);
        $this->app->forgetInstance(SearchIndexer::class);

        $item = $this->item(['title' => 'Basic badger']);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'Badger draft', 'status' => 'draft']);

        $this->assertSame(["item:{$item->id}"], $this->ids($this->editor, 'badger'));
        $this->assertCount(2, $this->ids($this->owner, 'badger'));
        $this->assertSame(0, $this->search($this->outsider, 'badger')['total']);
        $this->assertStringContainsString('<mark>', $this->search($this->editor, 'badger')['results'][0]['title_html']);
    }

    public function test_letters_that_unaccent_turns_into_quotes_are_harmless(): void
    {
        $item = $this->item(['title' => 'Oʻzbek tili kursi']);

        foreach (['aʼ', 'okʼːAʼ', 'ŉ', 'ʻokina', 'oʻzbek'] as $q) {
            $this->search($this->editor, $q); // no exception
        }
        $this->assertSame(["item:{$item->id}"], $this->ids($this->editor, 'oʻzbek'));
        $this->actingAs($this->editor)->get(route('search', ['q' => 'aʼ']))->assertOk();
    }

    public function test_one_letter_and_odd_parameters_are_handled(): void
    {
        $this->item(['title' => 'A apple']);

        $this->assertSame(0, $this->search($this->editor, 'a')['total']);
        $this->assertFalse(SearchService::queryFrom(['q' => 'a'], $this->editor)->isSearchable());
        $this->actingAs($this->editor)->get('/search?q=apple&updated[]=1&type[]=x&status[]=open&assignee[]=1&page[]=2&from[]=1')
            ->assertOk()->assertInertia(fn ($page) => $page->where('search.total', 1));
    }

    public function test_exclusions_on_the_basic_engine_keep_rows_without_keywords(): void
    {
        $this->app->singleton(SearchEngine::class, fn () => new BasicSearchEngine);
        $this->app->forgetInstance(SearchIndexer::class);

        $comment = Comment::create(['item_id' => $this->item(['title' => 'Holder'])->id, 'user_id' => $this->editor->id, 'body' => 'basic beaver note']);
        $this->assertSame(["comment:{$comment->id}"], $this->ids($this->editor, 'beaver -otter'));
    }

    public function test_the_parser_never_passes_query_syntax_through(): void
    {
        $parsed = QueryParser::parse("foo' | bar:* & !baz <-> (qux) -\"x y\" WEB-12");
        $tsQuery = QueryParser::toTsQuery($parsed['terms']);

        $this->assertSame(['WEB-12'], $parsed['keys']);
        $this->assertSame("'foo' & 'bar' & 'baz' & 'qux' & !('x' <-> 'y') & ('web' <-> '12':*)", $tsQuery);
        // and the database accepts whatever comes out
        $this->assertSame(0, $this->search($this->editor, "foo' | bar:* & !baz <-> (qux) \\ ' \" -")['total']);
    }

    /** @return list<string> */
    private static function tags(string $html): array
    {
        preg_match_all('/<[^>]*>/', $html, $m);

        return array_values(array_unique($m[0]));
    }
}
