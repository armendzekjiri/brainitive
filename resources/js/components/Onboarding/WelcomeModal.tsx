import { useState } from 'react';
import axios from 'axios';
import { usePage } from '@inertiajs/react';
import { FolderKanban, FileText, Headphones, Sparkles, ArrowRight } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { OnboardingFocus, PageProps } from '@/types';

const FOCUS_OPTIONS: {
    value: OnboardingFocus;
    label: string;
    description: string;
    Icon: typeof FolderKanban;
}[] = [
    {
        value: 'project_mgmt',
        label: 'Project management',
        description: 'Kanban boards, tasks, sprints.',
        Icon: FolderKanban,
    },
    {
        value: 'docs',
        label: 'Docs & wiki',
        description: 'Collaborative pages, notes, knowledge base.',
        Icon: FileText,
    },
    {
        value: 'support',
        label: 'Customer support',
        description: 'Helpdesk, tickets, embeddable widget.',
        Icon: Headphones,
    },
    {
        value: 'all',
        label: 'A bit of everything',
        description: 'I want the full workspace.',
        Icon: Sparkles,
    },
];

export function WelcomeModal() {
    const { auth, onboarding } = usePage<PageProps>().props;
    const [open, setOpen] = useState(() => !!auth?.user && !!onboarding && !onboarding.welcome_seen);
    const [step, setStep] = useState<1 | 2>(1);
    const [focus, setFocus] = useState<OnboardingFocus | null>(null);
    const [saving, setSaving] = useState(false);

    if (!auth?.user || !onboarding) return null;

    const handleFinish = async () => {
        setSaving(true);
        try {
            await axios.post(route('onboarding.welcome-seen'), {
                focus: focus ?? undefined,
            });
        } catch {
            // Non-fatal — user just sees the modal again next visit.
        } finally {
            setSaving(false);
            setOpen(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) handleFinish();
            }}
        >
            <DialogContent className="max-w-xl">
                {step === 1 ? (
                    <>
                        <DialogHeader>
                            <DialogTitle className="text-2xl">Welcome to Brainitive, {auth.user.name.split(' ')[0]}!</DialogTitle>
                            <DialogDescription>
                                What will you mostly use this workspace for? We'll tailor the quick tips to match.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2 py-2">
                            {FOCUS_OPTIONS.map(({ value, label, description, Icon }) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setFocus(value)}
                                    className={cn(
                                        'flex items-center gap-3 rounded-lg border-2 p-3 text-left transition-colors',
                                        focus === value
                                            ? 'border-primary bg-primary/5'
                                            : 'border-border hover:border-primary/50 hover:bg-accent/40',
                                    )}
                                >
                                    <div
                                        className={cn(
                                            'flex size-10 shrink-0 items-center justify-center rounded-md',
                                            focus === value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <Icon className="size-5" />
                                    </div>
                                    <div className="flex-1">
                                        <div className="text-sm font-medium">{label}</div>
                                        <div className="text-xs text-muted-foreground">{description}</div>
                                    </div>
                                </button>
                            ))}
                        </div>
                        <DialogFooter className="mt-2 flex items-center justify-between gap-2 sm:justify-between">
                            <Button variant="ghost" onClick={handleFinish} disabled={saving}>
                                Skip
                            </Button>
                            <Button onClick={() => setStep(2)} disabled={!focus}>
                                Next
                                <ArrowRight className="ml-1 size-4" />
                            </Button>
                        </DialogFooter>
                    </>
                ) : (
                    <>
                        <DialogHeader>
                            <DialogTitle className="text-2xl">You're all set.</DialogTitle>
                            <DialogDescription>
                                Here are three things worth trying first — there's a Setup checklist on your workspaces page to keep you on track.
                            </DialogDescription>
                        </DialogHeader>
                        <ul className="grid gap-3 py-2 text-sm">
                            <li className="flex items-start gap-3 rounded-lg border p-3">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">1</span>
                                <div>
                                    <div className="font-medium">Create a board</div>
                                    <div className="text-muted-foreground">Open a project and add your first kanban board.</div>
                                </div>
                            </li>
                            <li className="flex items-start gap-3 rounded-lg border p-3">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">2</span>
                                <div>
                                    <div className="font-medium">Write a page</div>
                                    <div className="text-muted-foreground">Notes, docs, RFCs — rich text with slash commands.</div>
                                </div>
                            </li>
                            <li className="flex items-start gap-3 rounded-lg border p-3">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">3</span>
                                <div>
                                    <div className="font-medium">Invite a teammate</div>
                                    <div className="text-muted-foreground">Collaborate from the start — everything is multiplayer.</div>
                                </div>
                            </li>
                        </ul>
                        <DialogFooter className="mt-2 flex items-center justify-between gap-2 sm:justify-between">
                            <Button variant="ghost" onClick={() => setStep(1)} disabled={saving}>
                                Back
                            </Button>
                            <Button onClick={handleFinish} disabled={saving}>
                                {saving ? 'Saving…' : 'Get started'}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
