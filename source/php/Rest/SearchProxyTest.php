<?php

declare(strict_types=1);

namespace AlgoliaIndexTypesenseProvider\Rest;

use PHPUnit\Framework\TestCase;

class SearchProxyTest extends TestCase
{
    public function testBuildQueryFixesEverythingButQueryAndPaging(): void
    {
        $query = SearchProxy::buildQuery('semester', '2', '10');

        $this->assertSame([
            'q' => 'semester',
            'query_by' => 'post_title,post_excerpt,content',
            'highlight_full_fields' => 'post_title,post_excerpt',
            'page' => 2,
            'per_page' => 10,
        ], $query);
    }

    public function testBuildQueryClampsPagingAndDefaultsInvalidInput(): void
    {
        $this->assertSame(1, SearchProxy::buildQuery('x', '0', null)['page']);
        $this->assertSame(SearchProxy::MAX_PAGE, SearchProxy::buildQuery('x', '9999', null)['page']);
        $this->assertSame(SearchProxy::MAX_PER_PAGE, SearchProxy::buildQuery('x', null, '500')['per_page']);
        $this->assertSame(20, SearchProxy::buildQuery('x', null, 'abc')['per_page']);
        $this->assertSame(1, SearchProxy::buildQuery('x', ['1'], null)['page']);
    }

    public function testBuildQueryTrimsAndLimitsTheQueryText(): void
    {
        $this->assertSame('', SearchProxy::buildQuery(null, null, null)['q']);
        $this->assertSame('', SearchProxy::buildQuery(['a'], null, null)['q']);
        $this->assertSame('ab', SearchProxy::buildQuery('  ab  ', null, null)['q']);
        $this->assertSame(
            SearchProxy::MAX_QUERY_LENGTH,
            mb_strlen(SearchProxy::buildQuery(str_repeat('å', 500), null, null)['q']),
        );
    }

    public function testBuildQueryNeverCarriesAFilter(): void
    {
        $query = SearchProxy::buildQuery('x) || (post_type:=page', null, null);

        $this->assertArrayNotHasKey('filter_by', $query);
        $this->assertArrayNotHasKey('facet_by', $query);
        $this->assertSame('x) || (post_type:=page', $query['q']);
    }

    public function testFilterHitsKeepsOnlyReadableDocuments(): void
    {
        $hits = [
            ['document' => ['ID' => 1]],
            ['document' => ['ID' => 2]],
            ['document' => 'not-a-document'],
            'not-a-hit',
            ['document' => ['ID' => 3]],
        ];

        $filtered = SearchProxy::filterHits($hits, static fn(array $document): bool => $document['ID'] !== 2);

        $this->assertSame([['document' => ['ID' => 1]], ['document' => ['ID' => 3]]], $filtered);
    }

    public function testIsProxyRouteMatchesOnlyTheProxyNamespace(): void
    {
        $this->assertTrue(SearchProxy::isProxyRoute('/algolia-index-typesense/v1/proxy/collections/site/documents/search'));
        $this->assertFalse(SearchProxy::isProxyRoute('/algolia-index-typesense/v1/proxy'));
        $this->assertFalse(SearchProxy::isProxyRoute('/wp/v2/posts'));
        $this->assertFalse(SearchProxy::isProxyRoute(''));
    }
}
