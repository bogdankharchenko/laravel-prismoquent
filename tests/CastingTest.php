<?php

namespace Galahad\Prismoquent\Tests;

class CastingTest extends TestCase
{
	public function test_structured_text_can_be_accessed_as_html() : void
	{
		$page = Page::find('W3RRKh0AADaEY847');
		
		// Test the body field access and casting
		$body = $page->getAttribute('body');
		
		$this->assertTrue(str_contains($body->toHtml(), '<strong>'));
	}
	
	public function test_structured_text_can_be_accessed_as_text() : void
	{
		$page = Page::find('W3RRKh0AADaEY847');
		
		// Test title which is cast as text
		$title = $page->getAttribute('title');
		
		$this->assertFalse(str_contains($title, '<strong>'));
		$this->assertTrue(str_contains($title, 'Demo Page'));
	}
}
