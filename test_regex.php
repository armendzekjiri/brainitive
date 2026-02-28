<?php
$content = '<p dir="ltr"><span data-lexical-mention="true" data-mention-user-id="2" data-mention-name="Test User">@Test</span></p>';
preg_match_all('/data-mention-user-id(?:\\\\)?"(?:\\\\)?:"?(\d+)/', $content, $matches1);
preg_match_all('/data-mention-user-id(?:\\\\)?="(?:\\\\)?(\d+)(?:\\\\)?"/', $content, $matches2);
$userIds = array_unique(array_merge($matches1[1] ?? [], $matches2[1] ?? []));
var_dump($userIds);
var_dump($matches2);
