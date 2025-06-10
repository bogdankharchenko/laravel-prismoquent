<?php

namespace Galahad\Prismoquent\Tests;

class SliceTest extends TestCase
{
	public function test_slices() : void
	{
		$page = Page::find('W3RRKh0AADaEY847');
		
		// In Prismic SDK v5, slices are stdClass objects
		$slices = $page->slices;
		
		$this->assertIsObject($slices);
		$this->assertEquals('SliceZone', $slices->type);
		$this->assertIsArray($slices->value);
		
		// Test first slice
		$slice = $slices->value[0];
		
		$this->assertIsObject($slice);
		$this->assertEquals('Slice', $slice->type);
		$this->assertEquals('quote', $slice->slice_type);
		$this->assertObjectHasProperty('non-repeat', $slice);
		
		// Test slice content
		$quoteBody = $slice->{'non-repeat'}->quote_body;
		$this->assertEquals('StructuredText', $quoteBody->type);
		$this->assertIsArray($quoteBody->value);
	}
}
