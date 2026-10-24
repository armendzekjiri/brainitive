import { useState, type FormEvent, type ReactNode } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, ArrowUp, FlaskConical, History, Plus, Save, Trash2, Workflow, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import RunLog from '@/components/automations/RunLog';
import TestRulePanel from '@/components/automations/TestRulePanel';
import ValueField, { selectClass } from '@/components/automations/ValueField';
import type { AutomationAction, AutomationCatalog, AutomationCatalogEntry, AutomationCondition, AutomationOptions, AutomationRule, AutomationRun, AutomationSubject } from '@/types';

/**
 * The automation rule builder (v1.40.0, BRAI-63): WHEN (trigger) / IF (conditions, all must match) /
 * THEN (ordered actions), "Test on an issue" (dry run) and, for a saved rule, its run history.
 * Saving a changed definition makes a new version; the rule then runs as you.
 */
interface Props {
    workspace: { id: number; name: string; slug: string };
    project: { id: number; name: string; slug: string; key: string };
    rule: AutomationRule | null;
    runs: AutomationRun[];
    catalog: AutomationCatalog;
    options: AutomationOptions;
}

interface FormData {
    name: string;
    trigger: string;
    trigger_config: { column_id?: number | null };
    conditions: AutomationCondition[];
    actions: AutomationAction[];
}

const fits = (entry: AutomationCatalogEntry, subject: AutomationSubject) => !entry.subjects || entry.subjects.includes(subject);

function Step({ word, title, description, children }: { word: string; title: string; description: string; children: ReactNode }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <span className="rounded-md bg-primary/10 px-2 py-0.5 text-xs font-bold tracking-wider text-primary">{word}</span>{title}
                </CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">{children}</CardContent>
        </Card>
    );
}

function FieldError({ message }: { message?: string }) {
    return message ? <p className="text-sm text-destructive">{message}</p> : null;
}

export default function AutomationsEdit({ workspace, project, rule, runs, catalog, options }: Props) {
    const params = [workspace.slug, project.slug];
    const form = useForm<FormData>({
        name: rule?.name ?? '',
        trigger: rule?.trigger ?? 'item.created',
        trigger_config: { column_id: rule?.trigger_config?.column_id ?? null },
        conditions: rule?.conditions ?? [],
        actions: rule?.actions ?? [],
    });
    const { data, setData, errors, processing } = form;
    const errorOf = (key: string) => (errors as Record<string, string | undefined>)[key];
    const [confirmDelete, setConfirmDelete] = useState(false);

    const trigger = catalog.triggers.find((t) => t.key === data.trigger) ?? catalog.triggers[0];
    const subject: AutomationSubject = trigger.subject ?? 'item';
    const conditionTypes = catalog.conditions.filter((c) => fits(c, subject));
    const actionTypes = catalog.actions.filter((a) => fits(a, subject));
    const conditionSpec = (type: string) => catalog.conditions.find((c) => c.key === type);
    const actionSpec = (type: string) => catalog.actions.find((a) => a.key === type);

    const changeTrigger = (key: string) => {
        const next = catalog.triggers.find((t) => t.key === key);
        const nextSubject = next?.subject ?? 'item';
        // conditions / actions that don't fit the new subject are dropped
        setData((d) => ({
            ...d,
            trigger: key,
            trigger_config: { column_id: key === 'item.moved' ? d.trigger_config.column_id ?? null : null },
            conditions: d.conditions.filter((c) => { const s = conditionSpec(c.type); return s ? fits(s, nextSubject) : false; }),
            actions: d.actions.filter((a) => { const s = actionSpec(a.type); return s ? fits(s, nextSubject) : false; }),
        }));
    };

    const setCondition = (i: number, patch: Partial<AutomationCondition>) =>
        setData('conditions', data.conditions.map((c, j) => (j === i ? { ...c, ...patch } : c)));
    const setAction = (i: number, patch: Partial<AutomationAction>) =>
        setData('actions', data.actions.map((a, j) => (j === i ? { ...a, ...patch } : a)));
    const moveAction = (i: number, by: -1 | 1) => {
        const next = [...data.actions];
        [next[i], next[i + by]] = [next[i + by], next[i]];
        setData('actions', next);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (rule) form.put(route('automations.update', [...params, rule.id]), { preserveScroll: true });
        else form.post(route('automations.store', params));
    };

    return (
        <AppLayout>
            <Head title={rule ? `${rule.name} - Automations` : 'New rule - Automations'} />
            <div className="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-8">
                <div>
                    <Link href={route('automations.index', params)} className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" />Automations
                    </Link>
                    <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight"><Workflow className="size-6" />{rule ? rule.name : 'New rule'}</h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {rule
                                    ? `${rule.enabled ? 'On' : 'Off'} · version ${rule.version} · runs as ${rule.actor?.name ?? 'nobody'}. Saving makes you the person it runs as.`
                                    : 'New rules start switched off. Turn them on in the list when you’re ready.'}
                            </p>
                            {rule?.actor_problem && <p className="mt-1 text-sm text-amber-700 dark:text-amber-300">{rule.actor_problem}</p>}
                        </div>
                        {rule && (
                            <Button variant="outline" className="gap-1.5 text-destructive" onClick={() => setConfirmDelete(true)}><Trash2 className="size-4" />Delete rule</Button>
                        )}
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="rule-name">Rule name</Label>
                        <Input id="rule-name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Triage new bugs" maxLength={120} required />
                        <FieldError message={errors.name} />
                    </div>

                    <Step word="WHEN" title="Trigger" description={trigger.help ?? ''}>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <select aria-label="When" className={selectClass} value={data.trigger} onChange={(e) => changeTrigger(e.target.value)}>
                                {catalog.triggers.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
                            </select>
                            {data.trigger === 'item.moved' && (
                                <select
                                    aria-label="To column"
                                    className={selectClass}
                                    value={data.trigger_config.column_id ?? ''}
                                    onChange={(e) => setData('trigger_config', { column_id: e.target.value === '' ? null : Number(e.target.value) })}
                                >
                                    <option value="">To any column</option>
                                    {options.boards.map((b) => (
                                        <optgroup key={b.id} label={b.name}>
                                            {b.columns.map((c) => <option key={c.id} value={c.id}>To {c.name}</option>)}
                                        </optgroup>
                                    ))}
                                </select>
                            )}
                        </div>
                        <FieldError message={errorOf('trigger') ?? errorOf('trigger_config.column_id')} />
                    </Step>

                    <Step word="IF" title="Conditions" description="All conditions must match. No conditions: the rule runs for every trigger.">
                        {data.conditions.map((condition, i) => {
                            const spec = conditionSpec(condition.type);
                            return (
                                <div key={i} className="space-y-1">
                                    <div className="flex items-start gap-2">
                                        <div className="grid flex-1 gap-2 sm:grid-cols-2">
                                            <select aria-label={`Condition ${i + 1}`} className={selectClass} value={condition.type} onChange={(e) => setCondition(i, { type: e.target.value, value: null })}>
                                                {conditionTypes.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                                            </select>
                                            {spec?.value && (
                                                <ValueField kind={spec.value} value={condition.value} onChange={(value) => setCondition(i, { value })} options={options} subject={subject} label={`Condition ${i + 1} value`} invalid={!!errorOf(`conditions.${i}.value`)} />
                                            )}
                                        </div>
                                        <Button type="button" variant="ghost" size="icon" aria-label={`Remove condition ${i + 1}`} onClick={() => setData('conditions', data.conditions.filter((_, j) => j !== i))}><X className="size-4" /></Button>
                                    </div>
                                    <FieldError message={errorOf(`conditions.${i}.type`) ?? errorOf(`conditions.${i}.value`)} />
                                </div>
                            );
                        })}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="gap-1.5"
                            disabled={data.conditions.length >= catalog.maxConditions}
                            onClick={() => setData('conditions', [...data.conditions, { type: conditionTypes[0].key, value: null }])}
                        >
                            <Plus className="size-3.5" />Add condition
                        </Button>
                        <FieldError message={errors.conditions} />
                    </Step>

                    <Step word="THEN" title="Actions" description={`Run in this order, as the rule's person, with their permissions at that moment. At most ${catalog.maxActions}.`}>
                        {data.actions.map((action, i) => {
                            const spec = actionSpec(action.type);
                            return (
                                <div key={i} className="space-y-2 rounded-lg border p-3">
                                    <div className="flex items-start gap-2">
                                        <span className="mt-2 w-5 shrink-0 text-xs font-semibold text-muted-foreground">{i + 1}.</span>
                                        <div className="grid flex-1 gap-2 sm:grid-cols-2">
                                            <select aria-label={`Action ${i + 1}`} className={selectClass} value={action.type} onChange={(e) => setAction(i, { type: e.target.value, value: null, text: null })}>
                                                {actionTypes.map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
                                            </select>
                                            {spec?.value && (
                                                <ValueField kind={spec.value} value={action.value} onChange={(value) => setAction(i, { value })} options={options} subject={subject} label={`Action ${i + 1} value`} invalid={!!errorOf(`actions.${i}.value`)} />
                                            )}
                                        </div>
                                        <div className="flex shrink-0">
                                            <Button type="button" variant="ghost" size="icon" aria-label={`Move action ${i + 1} up`} disabled={i === 0} onClick={() => moveAction(i, -1)}><ArrowUp className="size-4" /></Button>
                                            <Button type="button" variant="ghost" size="icon" aria-label={`Move action ${i + 1} down`} disabled={i === data.actions.length - 1} onClick={() => moveAction(i, 1)}><ArrowDown className="size-4" /></Button>
                                            <Button type="button" variant="ghost" size="icon" aria-label={`Remove action ${i + 1}`} onClick={() => setData('actions', data.actions.filter((_, j) => j !== i))}><X className="size-4" /></Button>
                                        </div>
                                    </div>
                                    {spec?.text && (
                                        <div className="pl-7">
                                            <Textarea
                                                aria-label={`Action ${i + 1} text`}
                                                rows={2}
                                                maxLength={2000}
                                                value={action.text ?? ''}
                                                onChange={(e) => setAction(i, { text: e.target.value })}
                                                placeholder={spec.text === 'required' ? 'Comment text. {{item.key}} and {{item.title}} are filled in.' : 'Message (optional). {{item.key}} and {{item.title}} are filled in.'}
                                            />
                                        </div>
                                    )}
                                    <div className="pl-7"><FieldError message={errorOf(`actions.${i}.type`) ?? errorOf(`actions.${i}.value`) ?? errorOf(`actions.${i}.text`)} /></div>
                                </div>
                            );
                        })}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="gap-1.5"
                            disabled={data.actions.length >= catalog.maxActions}
                            onClick={() => setData('actions', [...data.actions, { type: actionTypes[0].key, value: null, text: null }])}
                        >
                            <Plus className="size-3.5" />Add action
                        </Button>
                        <FieldError message={errors.actions} />
                    </Step>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" asChild><Link href={route('automations.index', params)}>Cancel</Link></Button>
                        <Button type="submit" disabled={processing} className="gap-1.5"><Save className="size-4" />{processing ? 'Saving…' : 'Save rule'}</Button>
                    </div>
                </form>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base"><FlaskConical className="size-4" />Test on {subject === 'ticket' ? 'a ticket' : 'an issue'}</CardTitle>
                        <CardDescription>Check the rule above (saved or not) against a real {subject === 'ticket' ? 'ticket' : 'issue'}, as you.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TestRulePanel url={route('automations.preview', params)} subject={subject} definition={data} />
                    </CardContent>
                </Card>

                {rule && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base"><History className="size-4" />Run history</CardTitle>
                            <CardDescription>The last 50 runs of this rule.</CardDescription>
                        </CardHeader>
                        <CardContent><RunLog runs={runs} /></CardContent>
                    </Card>
                )}
            </div>

            <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this rule?</AlertDialogTitle>
                        <AlertDialogDescription>“{rule?.name}” and its run history are deleted. Changes it made stay.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={() => rule && router.delete(route('automations.destroy', [...params, rule.id]))}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
