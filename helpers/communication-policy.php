<?php

function normalizeCommunicationRole(?string $role): string
{
    return strtolower(trim((string) $role));
}

function communicationAllowedRoles(string $role): array
{
    $policy = [
        'admin' => ['admin', 'hr'],
        'hr' => ['admin', 'hr', 'manager', 'supervisor'],
        'manager' => ['hr', 'manager', 'supervisor', 'staff'],
        'supervisor' => ['hr', 'manager', 'supervisor', 'staff'],
        'staff' => ['supervisor', 'manager'],
    ];

    return $policy[$role] ?? [];
}

function canDirectlyMessage(string $senderRole, string $receiverRole): bool
{
    return in_array($receiverRole, communicationAllowedRoles($senderRole), true);
}

function communicationArchitecture(string $role): array
{
    $role = normalizeCommunicationRole($role);

    $base = [
        [
            'channel' => 'Direct Messages',
            'audience' => 'People-based communication',
            'description' => 'Use direct messages for approvals, escalations, clarifications, and role-to-role follow-up.',
        ],
        [
            'channel' => 'Notifications',
            'audience' => 'System communication',
            'description' => 'Use notifications for alerts, reminders, approvals, task updates, and broadcast ETMS activity.',
        ],
        [
            'channel' => 'Announcements',
            'audience' => 'Organization-wide updates',
            'description' => 'Use announcements for official policy, events, and non-conversational company-wide communication.',
        ],
    ];

    $roleRoutes = [
        'admin' => [
            [
                'title' => 'Admin to HR',
                'description' => 'Admin communicates directly with HR for policy, governance, and escalated personnel matters.',
            ],
            [
                'title' => 'Admin to Wider Teams',
                'description' => 'Use notifications or announcements for wider distribution instead of direct operational messaging.',
            ],
        ],
        'hr' => [
            [
                'title' => 'HR to Manager/Supervisor',
                'description' => 'HR sends workforce direction through managers and supervisors, not directly to staff.',
            ],
            [
                'title' => 'HR to Admin',
                'description' => 'HR communicates directly with admin for governance, compliance, and executive HR escalation.',
            ],
        ],
        'manager' => [
            [
                'title' => 'Manager to Staff',
                'description' => 'Managers can communicate directly with staff and supervisors for operational coordination.',
            ],
            [
                'title' => 'Manager to HR',
                'description' => 'Managers escalate workforce and policy issues directly to HR when needed.',
            ],
        ],
        'supervisor' => [
            [
                'title' => 'Supervisor to Staff',
                'description' => 'Supervisors are the primary direct communication point for day-to-day staff coordination.',
            ],
            [
                'title' => 'Supervisor Upward Escalation',
                'description' => 'Supervisors can escalate to managers or HR for staffing, conduct, or compliance issues.',
            ],
        ],
        'staff' => [
            [
                'title' => 'Staff to Supervisor',
                'description' => 'Staff use supervisors as the primary communication route for operational questions and support.',
            ],
            [
                'title' => 'Staff to Manager',
                'description' => 'Managers act as the escalation route when supervisor-level resolution is not enough.',
            ],
        ],
    ];

    return [
        'channels' => $base,
        'routes' => $roleRoutes[$role] ?? [],
    ];
}

function communicationNotificationMeta(string $role): array
{
    $role = normalizeCommunicationRole($role);

    $meta = [
        'admin' => [
            'title' => 'Admin Notifications',
            'breadcrumb' => 'Dashboard / Admin / Notifications',
            'roleSummary' => 'Admin notifications should prioritize system-wide governance events, HR escalations, platform health alerts, and organization-wide communication visibility.',
        ],
        'hr' => [
            'title' => 'HR Notifications',
            'breadcrumb' => 'Dashboard / HR / Notifications',
            'roleSummary' => 'HR notifications should cover manager and supervisor escalations, policy-sensitive workflows, compliance reminders, and admin-level governance updates.',
        ],
        'manager' => [
            'title' => 'Manager Notifications',
            'breadcrumb' => 'Dashboard / Manager / Notifications',
            'roleSummary' => 'Manager notifications should highlight supervisor escalations, departmental approvals, staffing exceptions, and HR coordination requiring managerial visibility.',
        ],
        'supervisor' => [
            'title' => 'Supervisor Notifications',
            'breadcrumb' => 'Dashboard / Supervisor / Notifications',
            'roleSummary' => 'Supervisor notifications should surface staff escalations, attendance exceptions, task follow-up, manager requests, and HR workflow alerts.',
        ],
        'staff' => [
            'title' => 'Staff Notifications',
            'breadcrumb' => 'Dashboard / Staff / Notifications',
            'roleSummary' => 'Staff notifications should focus on supervisor-led updates, approved escalations, task alerts, attendance reminders, and organization-wide announcements.',
        ],
    ];

    return $meta[$role] ?? [
        'title' => 'Notifications',
        'breadcrumb' => 'Dashboard / Notifications',
        'roleSummary' => 'Notifications surface role-relevant alerts, reminders, approvals, and organization-wide communication.',
    ];
}
