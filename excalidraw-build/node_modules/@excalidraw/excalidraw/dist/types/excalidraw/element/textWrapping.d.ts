import type { FontString } from "./types";
/**
 * Test if a given text contains any CJK characters (including symbols, punctuation, etc,).
 */
export declare const containsCJK: (text: string) => boolean;
/**
 * Breaks the line into the tokens based on the found line break opporutnities.
 */
export declare const parseTokens: (line: string) => string[];
/**
 * Wraps the original text into the lines based on the given width.
 */
export declare const wrapText: (text: string, font: FontString, maxWidth: number) => string;
