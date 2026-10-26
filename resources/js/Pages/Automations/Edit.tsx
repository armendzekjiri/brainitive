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
import { useFlashToast } from '@/components/automations/useFlashToast';
import RunLog from '@/components/automations/RunLog';
import TestRulePanel from '@/components/automations/TestRulePanel';
import ValueField, { choices, selectClass } from '@/components/automations/ValueField';
import ConditionRow from '@/components/automations/ConditionRow';
import { blankCondition, describeCondition, fieldsFor, flatChoices, joinConditions } from '@/components/automations/conditions';
import { cn } from '@/lib/utils';
import type { AutomationAction, AutomationCatalog, AutomationCatalogEntry, AutomationCondition, AutomationMatch, AutomationOptions, AutomationRule, AutomationRun, AutomationSubject } from '@/types';

/**
 * The automation rule builder (v1.40.0, BRAI-63): WHEN (trigger) / IF (conditions: field → operator →
 * value rows, all or any must match, BRAI-144) / THEN (ordered actions), the rule as a sentence,
 * "Test on an issue" (dry run) and, for a saved rule, its run history.
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
    condition_match: AutomationMatch;
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

/** "Assign to Max Member", "Set due date today + 3 days" — for the sentence preview. */
function describeAction(action: AutomationAction, spec: AutomationCatalogEntry | undefined, options: AutomationOptions, subject: AutomationSubject): string {
    if (!spec) return action.type;
    if (!spec.value) return spec.label;
    if (action.value === null || action.value === '') return `${spec.label} …`;
    if (spec.value === 'days') return `${spec.label} today + ${action.value} ${action.value === 1 ? 'day' : 'days'}`;
    if (spec.value === 'text') return `${spec.label} "${action.value}"`;
    const name = flatChoices(choices(spec.value, options, subject)).find((o) => o.value === String(action.value))?.label;
    return `${spec.label} ${name ?? '…'}`;
}

function FieldError({ message }: { message?: string }) {
    return message ? <p className="text-sm text-destructive">{message}</p> : null;
}

export default function AutomationsEdit({ workspace, project, rule, runs, catalog, options }: Props) {
    useFlashToast();
    const params = [workspace.slug, project.slug];
    const form = useForm<FormData>({
        name: rule?.name ?? '',
        trigger: rule?.trigger ?? 'item.created',
        trigger_config: { column_id: rule?.trigger_config?.column_id ?? null },
        condition_match: rule?.condition_match ?? 'all',
        conditions: rule?.conditions ?? [],
        actions: rule?.actions ?? [],
    });
    const { data, setData, errors, processing } = form;
    const errorOf = (key: string) => (errors as Record<string, string | undefined>)[key];
    const [confirmDelete, setConfirmDelete] = useState(false);

    const trigger = catalog.triggers.find((t) => t.key === data.trigger) ?? catalog.triggers[0];
    const subject: AutomationSubject = trigger.subject ?? 'item';
    const conditionFields = fieldsFor(catalog, options, subject);
    const actionTypes = catalog.actions.filter((a) => fits(a, subject));
    const actionSpec = (type: string) => catalog.actions.find((a) => a.key === type);

    const changeTrigger = (key: string) => {
        const next = catalog.triggers.find((t) => t.key === key);
        const nextSubject = next?.subject ?? 'item';
        const nextFields = new Set(fieldsFor(catalog, options, nextSubject).map((f) => f.key));
        // conditions / actions that don't fit the new subject are dropped
        setData((d) => ({
            ...d,
            trigger: key,
            trigger_config: { column_id: key === 'item.moved' ? d.trigger_config.column_id ?? null : null },
            conditions: d.conditions.filter((c) => nextFields.has(c.field) || (nextSubject === subject && c.field.startsWith('custom:'))),
            actions: d.actions.filter((a) => { const s = actionSpec(a.type); return s ? fits(s, nextSubject) : false; }),
        }));
    };

    const setCondition = (i: number, condition: AutomationCondition) =>
        setData('conditions', data.conditions.map((c, j) => (j === i ? condition : c)));
    const moveCondition = (i: number, by: -1 | 1) => {
        const next = [...data.conditions];
        [next[i], next[i + by]] = [next[i + by], next[i]];
        setData('conditions', next);
    };
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

                    <Step word="IF" title="Conditions" description="Pick a field, how to compare it and the value. No conditions: the rule runs for every trigger.">
                        {data.conditions.length > 0 && (
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <span className="text-muted-foreground">Run when</span>
                                <div role="group" aria-label="Match" className="inline-flex rounded-md border p-0.5">
                                    {(['all', 'any'] as const).map((m) => (
                                        <button
                                            key={m}
                                            type="button"
                                            aria-pressed={data.condition_match === m}
                                            onClick={() => setData('condition_match', m)}
                                            className={cn(
                                                'rounded px-3 py-1 text-sm font-medium transition-colors',
                                                data.condition_match === m ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {m === 'all' ? 'All' : 'Any'}
                                        </button>
                                    ))}
                                </div>
                                <span className="text-muted-foreground">{data.condition_match === 'all' ? 'of these conditions match' : 'of these conditions matches'}</span>
                            </div>
                        )}
                        {data.conditions.map((condition, i) => (
                            <ConditionRow
                                key={i}
                                index={i}
                                count={data.conditions.length}
                                condition={condition}
                                fields={conditionFields}
                                catalog={catalog}
                                options={options}
                                subject={subject}
                                errors={{ field: errorOf(`conditions.${i}.field`), operator: errorOf(`conditions.${i}.operator`), value: errorOf(`conditions.${i}.value`) }}
                                onChange={(next) => setCondition(i, next)}
                                onMove={(by) => moveCondition(i, by)}
                                onRemove={() => setData('conditions', data.conditions.filter((_, j) => j !== i))}
                            />
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="gap-1.5"
                            disabled={data.conditions.length >= catalog.maxConditions || conditionFields.length === 0}
                            onClick={() => setData('conditions', [...data.conditions, blankCondition(catalog, conditionFields)])}
                        >
                            <Plus className="size-3.5" />Add condition
                        </Button>
                        <FieldError message={errors.conditions ?? errorOf('condition_match')} />
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

                    <div className="rounded-lg border border-dashed bg-muted/30 p-3 text-sm" aria-label="Rule in words" role="note">
                        <span className="font-semibold">When</span> {trigger.label}
                        {data.trigger === 'item.moved' && data.trigger_config.column_id ? ` to ${flatChoices(choices('column', options, subject)).find((o) => o.value === String(data.trigger_config.column_id))?.label ?? '…'}` : ''}
                        {data.conditions.length > 0 && (
                            <>
                                {', '}<span className="font-semibold">if</span>{' '}
                                {joinConditions(data.conditions.map((c) => describeCondition(c, catalog, options, subject)), data.condition_match)}
                            </>
                        )}
                        {', '}<span className="font-semibold">then</span>{' '}
                        {data.actions.length > 0 ? data.actions.map((a) => describeAction(a, actionSpec(a.type), options, subject)).join(', ') : '…'}
                    </div>

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
