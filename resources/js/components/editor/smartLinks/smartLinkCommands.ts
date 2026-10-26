import { createCommand, type LexicalCommand } from 'lexical';
import type { SmartLinkView } from '@/types';

/** Open the smart link picker; `afterBlockKey` inserts the link in a new paragraph after that block ("+" menu). */
export const OPEN_SMART_LINK_PICKER_COMMAND: LexicalCommand<{ afterBlockKey?: string } | undefined> = createCommand('OPEN_SMART_LINK_PICKER_COMMAND');

/** Insert a smart link at the selection. */
export const INSERT_SMART_LINK_COMMAND: LexicalCommand<{ ref: string; url: string; text: string; view?: SmartLinkView }> = createCommand('INSERT_SMART_LINK_COMMAND');
