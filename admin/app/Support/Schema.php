<?php

namespace App\Support;

/**
 * Schema.org nodes shared by more than one public page, built in one place so
 * the product is described identically wherever it appears.
 *
 * These are page-specific additions to the @graph that partials.seo-head
 * already emits (Organization, WebSite, WebPage, BreadcrumbList). Pass them
 * to a view as `jsonLd`.
 *
 * The rule for everything here is the same as in seo-head: describe what is
 * visible on the page, and nothing more. No ratings, no review counts, no
 * prices that the page itself does not show.
 */
class Schema
{
    /**
     * The product itself. One @id across the site, so every page that
     * carries it is talking about the same entity.
     *
     * No `offers` and no `aggregateRating`: prices are quoted per visitor in
     * their own currency (so no single figure is true for everyone) and
     * there are no published reviews to aggregate.
     */
    public static function software(): array
    {
        $brand = (string) tva_setting('content.brand_name', 'serveAI');

        return [
            '@type'                  => 'SoftwareApplication',
            '@id'                    => Seo::origin() . '/#software',
            'name'                   => $brand,
            'applicationCategory'    => 'BusinessApplication',
            'applicationSubCategory' => 'Customer support software',
            'operatingSystem'        => 'Web browser',
            'url'                    => Seo::origin() . '/',
            'description'            => (string) tva_setting('content.hero_subtitle', ''),
            'featureList'            => array_values(array_filter(array_map(
                fn ($i) => strip_tags((string) tva_setting("content.feat{$i}_title", '')),
                range(1, 12)
            ))),
            'publisher'              => ['@id' => Seo::origin() . '/#organization'],
        ];
    }

    /**
     * FAQPage built from the exact array the page renders, so the markup
     * can never claim a question the reader cannot see.
     *
     * @param  array<int,array{0:string,1:string}>  $faqs  [question, answer] pairs
     */
    public static function faqPage(string $path, array $faqs): array
    {
        return [
            '@type'      => 'FAQPage',
            '@id'        => Seo::canonical($path) . '#faq',
            'mainEntity' => array_values(array_map(fn ($f) => [
                '@type'          => 'Question',
                'name'           => trim(strip_tags((string) $f[0])),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(strip_tags((string) $f[1]))],
            ], $faqs)),
        ];
    }
}
