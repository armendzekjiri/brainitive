<?php

$content = '<p dir="ltr"><span class="mention" data-mention-user-id="2" data-mention-name="Armend Zekjiri" style="background: hsl(var(--primary) / 0.1); color: hsl(var(--primary)); border-radius: 4px; padding: 1px 4px; font-weight: 500; cursor: default;">@Armend Zekjiri</span></p>';

preg_match_all('/data-mention-user-id(?:\\\\)?"(?:\\\\)?:"?(\d+)/', $content, $matches1);
preg_match_all('/data-mention-user-id(?:\\\\)?="(?:\\\\)?(\d+)(?:\\\\)?"/', $content, $matches2);

$userIds = array_unique(array_merge($matches1[1] ?? [], $matches2[1] ?? []));
echo 'Matches 1: ';
print_r($matches1);
echo 'Matches 2: ';
print_r($matches2);
echo 'User IDs: ';
print_r($userIds);
