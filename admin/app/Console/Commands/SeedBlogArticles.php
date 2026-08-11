<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use Illuminate\Console\Command;

/**
 * Loads the prepared articles into the blog as DRAFTS, ready to review at
 * /admin/blog.
 *
 * Article bodies live as HTML files in resources/content/blog/<slug>.html
 * rather than inline here. That keeps this command readable, lets the copy
 * be edited without touching PHP, and means a 15 KB article is not sitting
 * inside a heredoc.
 *
 * Drafts, never published: an article should be read by a human before it
 * is public, and these carry SEO metadata that deserves a second pair of
 * eyes. Publish from the ops console when you are happy with it.
 *
 * Idempotent — matched on slug, so re-running updates rather than
 * duplicating. It will NOT overwrite an article you have already published
 * or edited by hand (see --force).
 *
 *   php artisan blog:seed-articles              # add/refresh drafts
 *   php artisan blog:seed-articles --force      # also overwrite edited drafts
 *   php artisan blog:seed-articles --no-covers  # skip cover generation
 */
class SeedBlogArticles extends Command
{
    protected $signature = 'blog:seed-articles
                            {--force : Overwrite drafts that have been edited since seeding}
                            {--no-covers : Do not generate cover images}';

    protected $description = 'Load the prepared blog articles into /admin/blog as drafts';

    public function handle(): int
    {
        $seeded = $skipped = 0;

        foreach ($this->articles() as $article) {
            $body = $this->body($article['slug']);
            if ($body === null) {
                $this->error("  missing resources/content/blog/{$article['slug']}.html — skipped");
                $skipped++;
                continue;
            }

            $existing = BlogPost::withTrashed()->where('slug', $article['slug'])->first();

            if ($existing) {
                // Never touch something already live — that would silently
                // rewrite a page Google may have indexed.
                if ($existing->status === BlogPost::STATUS_PUBLISHED) {
                    $this->line("  skip   {$article['slug']} — already published");
                    $skipped++;
                    continue;
                }

                // A draft touched after creation has been edited by a human.
                // Their work wins unless --force.
                $edited = $existing->updated_at && $existing->created_at
                    && $existing->updated_at->gt($existing->created_at->addMinute());

                if ($edited && ! $this->option('force')) {
                    $this->line("  skip   {$article['slug']} — edited since seeding (use --force to overwrite)");
                    $skipped++;
                    continue;
                }
            }

            $post = $existing ?: new BlogPost();
            $post->fill($article);
            $post->body         = $body;
            $post->slug         = $article['slug'];
            $post->status       = BlogPost::STATUS_DRAFT;   // review before publishing
            $post->published_at = null;
            $post->deleted_at   = null;                     // un-trash if it was deleted
            $post->save();

            $this->info("  seeded {$article['slug']}  ({$post->reading_time} min read)");
            $seeded++;
        }

        $this->newLine();
        $this->line("{$seeded} article(s) seeded as drafts, {$skipped} skipped.");

        if (! $this->option('no-covers')) {
            $this->newLine();
            $this->call('blog:covers');
        }

        $this->newLine();
        $this->line('Review and publish at ' . url('/admin/blog'));
        $this->comment('Articles are seeded WITHOUT links to unpublished siblings —');
        $this->comment('add those cross-links once the related articles are live.');

        return self::SUCCESS;
    }

    /** Article body HTML, or null when the file is missing. */
    private function body(string $slug): ?string
    {
        $path = resource_path("content/blog/{$slug}.html");

        return is_file($path) ? trim((string) file_get_contents($path)) : null;
    }

    /**
     * Metadata for each article. The body comes from the matching file in
     * resources/content/blog/.
     *
     * Cross-links to articles that are not written yet are deliberately
     * omitted from the bodies: a live 404 inside body copy is worse than a
     * missing link.
     */
    private function articles(): array
    {
        return [
            [
                'slug'     => 'ai-agents-vs-chatbots-vs-assistants',
                'title'    => 'AI agents vs chatbots vs AI assistants: what\'s actually different',
                'subtitle' => 'A plain-English taxonomy you can use in a buying decision.',
                'category' => 'Guides',
                'tags'     => ['ai agents', 'chatbots', 'ai assistants', 'buying guide'],
                'excerpt'  => 'Three vendors will call the same product a chatbot, an assistant and an agent. The difference is real, and it comes down to one question: how much does the system decide on its own?',
                'meta_title'       => 'AI Agents vs Chatbots vs AI Assistants: What\'s Actually Different',
                'meta_description' => 'A plain-English taxonomy of chatbots, AI assistants and AI agents — what each can do, where each fails, and how to tell which one a vendor is really selling you.',
                'meta_keywords'    => 'ai agents vs chatbots, ai assistant vs ai agent, what is an ai agent, types of ai agents',
                'cover_alt'        => 'Comparison of rule-based chatbots, AI assistants and AI agents by how much each decides on its own',
                'author_name'      => 'Serve AI',
                'author_role'      => 'Product team',
                'is_featured'      => true,
            ],
            [
                'slug'     => 'ai-voice-agents-how-they-work-cost',
                'title'    => 'AI voice agents: how they work, where they fail, and what they cost',
                'subtitle' => 'The pipeline, the latency problem, and the numbers vendors are vague about.',
                'category' => 'Guides',
                'tags'     => ['ai voice agents', 'ai call agent', 'latency', 'pricing'],
                'excerpt'  => 'A voice agent is four systems in a chain, and each one adds delay. How the pipeline works, why latency is the hard part, and what actually drives the price per minute.',
                'meta_title'       => 'AI Voice Agents: How They Work, Where They Fail & What They Cost',
                'meta_description' => 'How AI voice agents actually work — the speech-to-text, LLM and text-to-speech pipeline, why latency is the hard problem, what drives cost per minute, and when not to use one.',
                'meta_keywords'    => 'ai voice agent, ai call agent, ai phone agent, voice ai latency, voice ai cost per minute',
                'cover_alt'        => 'The four stages of an AI voice agent pipeline and where the delay accumulates',
                'author_name'      => 'Serve AI',
                'author_role'      => 'Product team',
            ],
            [
                'slug'     => 'ai-lead-qualification-workflow',
                'title'    => 'AI lead qualification: building a workflow that actually filters',
                'subtitle' => 'Most "AI qualification" is a contact form with extra steps. Here is the difference.',
                'category' => 'Playbooks',
                'tags'     => ['lead qualification', 'sales automation', 'crm'],
                'excerpt'  => 'Qualification is not asking more questions — it is deciding, from the answers, who deserves a human. A practical framework, and the mistakes that make it useless.',
                'meta_title'       => 'AI Lead Qualification: Building a Workflow That Actually Filters',
                'meta_description' => 'A practical framework for automated lead qualification — what to ask, how to score, when to route to a human, and why most AI qualification adds work instead of removing it.',
                'meta_keywords'    => 'ai lead qualification, automated lead qualification, ai lead scoring, lead routing',
                'cover_alt'        => 'An enquiry being qualified, scored and routed to either a human or automated follow-up',
                'author_name'      => 'Serve AI',
                'author_role'      => 'Product team',
            ],
            [
                'slug'     => 'why-ai-support-projects-fail',
                'title'    => 'Why AI support projects fail — and the checks that prevent it',
                'subtitle' => 'Gartner expects more than 40% of agentic AI projects to be cancelled by 2027.',
                'category' => 'Guides',
                'tags'     => ['implementation', 'ai strategy', 'customer support'],
                'excerpt'  => 'The failures are not exotic. They cluster into six causes, every one of them visible before you sign. A pre-deployment checklist built from what actually goes wrong.',
                'meta_title'       => 'Why AI Support Projects Fail — and the Checks That Prevent It',
                'meta_description' => 'Gartner expects over 40% of agentic AI projects to be cancelled by 2027. The six reasons AI support deployments fail, and a pre-launch checklist that catches each one.',
                'meta_keywords'    => 'ai customer service implementation, ai project failure, ai implementation checklist',
                'cover_alt'        => 'Six common causes of AI support project failure and the pre-launch check that catches each',
                'author_name'      => 'Serve AI',
                'author_role'      => 'Product team',
            ],
        ];
    }
}
