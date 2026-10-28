---
title: Browser extension
description: Save pages, issues with screenshots, bookmarks, clipped docs, tasks and snippets to Brainitive from Chrome, Edge or Firefox — Cloud or self-hosted.
order: 90
---

# Browser extension

The Brainitive extension saves the page you're on to Brainitive. It works in Chrome, Edge and Firefox, with
Brainitive Cloud and with self-hosted instances.

## What it does

| Feature | How |
|---|---|
| Popup | Click the toolbar button or press <kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>B</kbd>, then save the page as an **Issue**, **Bookmark**, **Doc**, **Task** or **Snippet**, or send it to your **Inbox** to sort later. The form is prefilled from the page and your selection. |
| Screenshots | Attach the visible area to a new issue, or drag a rectangle with **Select area** (<kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd>) |
| Doc clipping | Save an article, your selection or just the link as a clean doc, as a draft or published |
| Right-click menu | Save a link, create an issue from the selection or page, clip it, or screenshot an area |
| Address bar | Type `bt`, a space, then your search |
| Side panel | **My Work**: your open issues and tasks, plus search |

## Install

Store listings are coming. Until then, download the extension from the latest
[GitHub release](https://github.com/armendzekjiri/brainitive/releases/latest):

- **Chrome & Edge:** download `brainitive-extension-chrome.zip` and unzip it. Open `chrome://extensions`
  (Edge: `edge://extensions`), turn on **Developer mode**, click **Load unpacked** and choose the unzipped folder.
  Then pin Brainitive from the puzzle icon.
- **Firefox:** download `brainitive-extension-firefox.zip`. Open `about:debugging` → **This Firefox** →
  **Load Temporary Add-on**, then pick the zip.

## Sign in

Open the extension and sign in. For a self-hosted instance, choose **Self-hosted** and enter your domain, for
example `brainitive.example.com`. The browser then asks for permission to access that site.

The extension can only save content and search titles. It can't use the MCP server or the rest of the API. To
disconnect it, go to **your avatar → Profile settings → Connected apps**, where it's listed as "Save pages, links
and notes".

## Self-hosting

There's nothing to set up. The extension registers itself over OAuth, using the same keys as the
[MCP server](./ai-and-mcp#self-hosting-the-mcp-server). Your instance needs HTTPS, except on `localhost`.
