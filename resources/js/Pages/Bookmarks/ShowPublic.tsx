import { Head } from '@inertiajs/react';
import { Bookmark, BookmarkItem } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Link2,
    FileText,
    File,
    FileImage,
    FileSpreadsheet,
    FileArchive,
    FileCode,
    FileType,
    ListTodo,
    StickyNote,
    ExternalLink,
    Eye,
    Download,
} from 'lucide-react';
import { useState } from 'react';

interface Props {
    bookmark: Bookmark & { items: BookmarkItem[]; creator?: { id: number; name: string } };
}

function getFileIcon(filename: string): { Icon: React.ComponentType<{ className?: string }>; className: string } {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    const map: Record<string, { Icon: React.ComponentType<{ className?: string }>; className: string }> = {
        pdf: { Icon: FileText, className: 'text-red-600' },
        doc: { Icon: FileText, className: 'text-blue-600' },
        docx: { Icon: FileText, className: 'text-blue-600' },
        rtf: { Icon: FileText, className: 'text-blue-500' },
        txt: { Icon: FileType, className: 'text-muted-foreground' },
        md: { Icon: FileType, className: 'text-muted-foreground' },
        xls: { Icon: FileSpreadsheet, className: 'text-green-600' },
        xlsx: { Icon: FileSpreadsheet, className: 'text-green-600' },
        csv: { Icon: FileSpreadsheet, className: 'text-green-500' },
        ppt: { Icon: FileText, className: 'text-orange-600' },
        pptx: { Icon: FileText, className: 'text-orange-600' },
        jpg: { Icon: FileImage, className: 'text-purple-500' },
        jpeg: { Icon: FileImage, className: 'text-purple-500' },
        png: { Icon: FileImage, className: 'text-sky-500' },
        gif: { Icon: FileImage, className: 'text-pink-500' },
        webp: { Icon: FileImage, className: 'text-teal-500' },
        bmp: { Icon: FileImage, className: 'text-muted-foreground' },
        ico: { Icon: FileImage, className: 'text-amber-500' },
        tiff: { Icon: FileImage, className: 'text-muted-foreground' },
        tif: { Icon: FileImage, className: 'text-muted-foreground' },
        svg: { Icon: FileImage, className: 'text-amber-500' },
        heic: { Icon: FileImage, className: 'text-muted-foreground' },
        heif: { Icon: FileImage, className: 'text-muted-foreground' },
        avif: { Icon: FileImage, className: 'text-muted-foreground' },
        zip: { Icon: FileArchive, className: 'text-amber-600' },
        rar: { Icon: FileArchive, className: 'text-amber-600' },
        '7z': { Icon: FileArchive, className: 'text-amber-600' },
        json: { Icon: FileCode, className: 'text-amber-500' },
        xml: { Icon: FileCode, className: 'text-orange-500' },
    };
    return map[ext] ?? { Icon: File, className: 'text-muted-foreground' };
}

type PreviewType = 'pdf' | 'image' | 'video' | 'other';
function getFilePreviewType(filename: string): PreviewType {
    const ext = filename?.split('.').pop()?.toLowerCase() ?? '';
    const pdf = ['pdf'];
    const image = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'tiff', 'tif', 'svg', 'heic', 'heif', 'avif'];
    const video = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'];
    if (pdf.includes(ext)) return 'pdf';
    if (image.includes(ext)) return 'image';
    if (video.includes(ext)) return 'video';
    return 'other';
}

export default function BookmarksShowPublic({ bookmark }: Props) {
    const items = bookmark.items ?? [];
    const links = items.filter((i) => i.type === 'link');
    const files = items.filter((i) => i.type === 'file');
    const todos = items.filter((i) => i.type === 'todo');
    const notes = items.filter((i) => i.type === 'note');

    const [filePreview, setFilePreview] = useState<{ url: string; title: string; previewType: PreviewType } | null>(null);

    return (
        <>
            <Head title={bookmark.name} />

            <div className="min-h-screen bg-gradient-to-b from-muted/30 to-background">
                <div className="max-w-4xl mx-auto p-6 md:p-8 lg:p-10 space-y-8">
                    <p className="text-center text-sm text-muted-foreground">Public bookmark (view only)</p>

                    {/* Header card */}
                    <Card className="overflow-hidden">
                        <CardContent className="p-6 md:p-8">
                            <div className="flex items-start gap-4">
                                <span className="text-4xl md:text-5xl shrink-0">{bookmark.icon || '📑'}</span>
                                <div className="min-w-0 flex-1">
                                    <h1 className="text-2xl md:text-3xl font-bold tracking-tight">{bookmark.name}</h1>
                                    {bookmark.description && (
                                        <p className="text-muted-foreground mt-2">{bookmark.description}</p>
                                    )}
                                    {bookmark.creator && (
                                        <p className="text-sm text-muted-foreground mt-3">by {bookmark.creator.name}</p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* 2x2 grid: Links, Files, To-dos, Notes */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                        {/* Links */}
                        <Card className="flex flex-col overflow-hidden">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium flex items-center gap-2">
                                    <Link2 className="size-4 text-muted-foreground" />
                                    Links
                                    {links.length > 0 && (
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium tabular-nums ml-auto">
                                            {links.length}
                                        </span>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex-1 min-h-0 overflow-auto pt-0">
                                {links.length > 0 ? (
                                    <ul className="space-y-1">
                                        {links.map((item) => (
                                            <li key={item.id}>
                                                <a
                                                    href={item.url || '#'}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="flex items-start gap-2 rounded-lg p-2 -mx-2 text-primary hover:bg-muted/50 transition-colors group"
                                                >
                                                    {item.metadata?.favicon_url ? (
                                                        <img
                                                            src={item.metadata.favicon_url}
                                                            alt=""
                                                            className="size-4 shrink-0 rounded-sm mt-0.5"
                                                        />
                                                    ) : (
                                                        <ExternalLink className="size-4 shrink-0 mt-0.5 text-muted-foreground group-hover:text-primary" />
                                                    )}
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate font-medium">{item.title || item.url || 'Link'}</span>
                                                        {item.metadata?.description && (
                                                            <span className="block text-xs text-muted-foreground line-clamp-2 mt-0.5">
                                                                {item.metadata.description}
                                                            </span>
                                                        )}
                                                    </span>
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-muted-foreground py-4 text-center">No links</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Files – with icons, preview, download */}
                        <Card className="flex flex-col overflow-hidden">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium flex items-center gap-2">
                                    <FileText className="size-4 text-muted-foreground" />
                                    Files
                                    {files.length > 0 && (
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium tabular-nums ml-auto">
                                            {files.length}
                                        </span>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex-1 min-h-0 overflow-auto pt-0">
                                {files.length > 0 ? (
                                    <ul className="space-y-0 divide-y divide-border/50">
                                        {files.map((item) => {
                                            const { Icon, className } = getFileIcon(item.title || '');
                                            const previewType = getFilePreviewType(item.title || '');
                                            const canPreview = previewType !== 'other';
                                            const fileUrl = item.file_url || '#';
                                            return (
                                                <li
                                                    key={item.id}
                                                    className="group flex items-center gap-2 px-2 py-2.5 -mx-2 first:mt-0 hover:bg-muted/30 transition-colors rounded-lg"
                                                >
                                                    <Icon className={`size-4 shrink-0 ${className}`} />
                                                    <a
                                                        href={fileUrl}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="flex-1 min-w-0 truncate text-sm text-primary hover:underline"
                                                    >
                                                        {item.title || 'File'}
                                                    </a>
                                                    <div className="flex items-center shrink-0 gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                                        {canPreview && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8"
                                                                onClick={() =>
                                                                    setFilePreview({
                                                                        url: fileUrl,
                                                                        title: item.title || 'File',
                                                                        previewType,
                                                                    })
                                                                }
                                                                title="Preview"
                                                            >
                                                                <Eye className="size-4" />
                                                            </Button>
                                                        )}
                                                        <a
                                                            href={fileUrl}
                                                            download={item.title || undefined}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex items-center justify-center size-8 rounded-md hover:bg-muted"
                                                            title="Download"
                                                        >
                                                            <Download className="size-4" />
                                                        </a>
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-muted-foreground py-4 text-center">No files</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* To-dos */}
                        <Card className="flex flex-col overflow-hidden">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium flex items-center gap-2">
                                    <ListTodo className="size-4 text-muted-foreground" />
                                    To-dos
                                    {(todos.length > 0) && (
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium tabular-nums ml-auto">
                                            {todos.filter((t) => t.is_completed).length}/{todos.length}
                                        </span>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex-1 min-h-0 overflow-auto pt-0">
                                {todos.length > 0 ? (
                                    <ul className="space-y-0">
                                        {todos.map((item) => (
                                            <li
                                                key={item.id}
                                                className={`flex items-center gap-2 py-2 px-2 -mx-2 rounded-lg ${
                                                    item.is_completed ? 'text-muted-foreground line-through' : ''
                                                }`}
                                            >
                                                <span className="text-lg shrink-0" aria-hidden>
                                                    {item.is_completed ? '✓' : '○'}
                                                </span>
                                                <span className="truncate">{item.title || 'To-do'}</span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-muted-foreground py-4 text-center">No to-dos</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Notes */}
                        <Card className="flex flex-col overflow-hidden">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium flex items-center gap-2">
                                    <StickyNote className="size-4 text-muted-foreground" />
                                    Notes
                                    {notes.length > 0 && (
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium tabular-nums ml-auto">
                                            {notes.length}
                                        </span>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex-1 min-h-0 overflow-auto pt-0">
                                {notes.length > 0 ? (
                                    <ul className="space-y-3">
                                        {notes.map((item) => (
                                            <li key={item.id} className="rounded-lg p-2 -mx-2 hover:bg-muted/30">
                                                <p className="text-sm whitespace-pre-wrap">{item.content || item.title || 'Note'}</p>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-muted-foreground py-4 text-center">No notes</p>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {items.length === 0 && (
                        <Card className="border-2 border-dashed">
                            <CardContent className="py-12 text-center text-muted-foreground">
                                <p>This bookmark has no content yet.</p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            {/* File preview modal */}
            <Dialog open={!!filePreview} onOpenChange={(open) => !open && setFilePreview(null)}>
                <DialogContent className="max-w-4xl max-h-[90vh] flex flex-col p-0 overflow-hidden">
                    {filePreview && (
                        <>
                            <DialogHeader className="px-6 py-3 border-b shrink-0">
                                <DialogTitle className="truncate pr-8">{filePreview.title}</DialogTitle>
                            </DialogHeader>
                            <div className="flex-1 min-h-0 overflow-auto p-6 bg-muted/30">
                                {filePreview.previewType === 'pdf' && (
                                    <iframe
                                        src={filePreview.url}
                                        title={filePreview.title}
                                        className="w-full h-[70vh] rounded border bg-background"
                                    />
                                )}
                                {filePreview.previewType === 'image' && (
                                    <img
                                        src={filePreview.url}
                                        alt={filePreview.title}
                                        className="max-w-full max-h-[70vh] object-contain mx-auto rounded"
                                    />
                                )}
                                {filePreview.previewType === 'video' && (
                                    <video
                                        src={filePreview.url}
                                        controls
                                        className="w-full max-h-[70vh] rounded"
                                        title={filePreview.title}
                                    />
                                )}
                                {filePreview.previewType === 'other' && (
                                    <div className="text-center py-8 text-muted-foreground">
                                        <p className="mb-4">Preview not available for this file type.</p>
                                        <a
                                            href={filePreview.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary hover:underline"
                                        >
                                            Open in new tab
                                        </a>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
