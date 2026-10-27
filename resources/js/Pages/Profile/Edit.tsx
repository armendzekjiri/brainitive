import AppLayout from '@/Layouts/AppLayout';
import { NotificationCategoryPreference, PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import OfflineSettingCard from '@/components/offline/OfflineSettingCard';
import ConnectedAppsForm, { ConnectedApp } from './Partials/ConnectedAppsForm';
import DeleteUserForm from './Partials/DeleteUserForm';
import DownloadDataForm from './Partials/DownloadDataForm';
import NotificationPreferencesForm from './Partials/NotificationPreferencesForm';
import NotificationSettingsForm from './Partials/NotificationSettingsForm';
import TaskDigestSetting from './Partials/TaskDigestSetting';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
    connectedApps = [],
    notificationCategories = [],
}: PageProps<{ mustVerifyEmail: boolean; status?: string; connectedApps?: ConnectedApp[]; notificationCategories?: NotificationCategoryPreference[] }>) {
    return (
        <AppLayout>
            <Head title="Profile" />

            <div className="p-6 md:p-8 lg:p-10 max-w-3xl mx-auto space-y-6">
                <div className="mb-8">
                    <h1 className="text-2xl font-bold tracking-tight">Profile</h1>
                    <p className="text-muted-foreground mt-1">Manage your account settings</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Profile information</CardTitle>
                        <CardDescription>Update your account name and email address.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </CardContent>
                </Card>

                <Card id="notifications" className="scroll-mt-20">
                    <CardHeader>
                        <CardTitle>Notifications</CardTitle>
                        <CardDescription>Choose what reaches you, and where: in the app, as a browser push, or by email.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <NotificationPreferencesForm categories={notificationCategories} />
                        <NotificationSettingsForm className="max-w-xl border-t pt-6" />
                        <TaskDigestSetting className="max-w-xl border-t pt-6" />
                    </CardContent>
                </Card>

                <Card id="offline" className="scroll-mt-20">
                    <CardHeader>
                        <CardTitle>Offline</CardTitle>
                        <CardDescription>Keep working without internet on this device.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <OfflineSettingCard className="max-w-xl" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Update password</CardTitle>
                        <CardDescription>Ensure your account is using a long, random password to stay secure.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <UpdatePasswordForm className="max-w-xl" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Connected apps</CardTitle>
                        <CardDescription>AI connectors (MCP), the browser extension and the CLI that can access your account. Changing your password disconnects all of them.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ConnectedAppsForm apps={connectedApps} className="max-w-xl" />
                    </CardContent>
                </Card>

                <Card id="your-data" className="scroll-mt-20">
                    <CardHeader>
                        <CardTitle>Your data</CardTitle>
                        <CardDescription>Get a copy of the data Brainitive holds about you (GDPR Art. 15 and 20).</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DownloadDataForm className="max-w-xl" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Delete account</CardTitle>
                        <CardDescription>Permanently delete your account and all associated data.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DeleteUserForm className="max-w-xl" />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
