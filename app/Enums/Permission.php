<?php

namespace App\Enums;

/**
 * Everything an admin can grant an employee through a staff role.
 *
 * Only grantable permissions belong here. User management, staff roles, hotel
 * settings, the AI advisor and usage are deliberately absent: they stay with
 * admins, so no role can hand an employee the means to raise their own access.
 */
enum Permission: string
{
    case ACTIVITIES_VIEW = 'activities.view';
    case ACTIVITIES_CREATE = 'activities.create';
    case ACTIVITIES_UPDATE = 'activities.update';
    case ACTIVITIES_DELETE = 'activities.delete';

    case ACTIVITY_CATEGORIES_VIEW = 'activity_categories.view';
    case ACTIVITY_CATEGORIES_CREATE = 'activity_categories.create';
    case ACTIVITY_CATEGORIES_UPDATE = 'activity_categories.update';
    case ACTIVITY_CATEGORIES_DELETE = 'activity_categories.delete';

    case AI_INSIGHTS_VIEW = 'ai_insights.view';
    case AI_INSIGHTS_GENERATE = 'ai_insights.generate';

    case BOOKINGS_VIEW = 'bookings.view';
    case BOOKINGS_CREATE = 'bookings.create';
    case BOOKINGS_UPDATE_STATUS = 'bookings.update_status';

    case DASHBOARD_VIEW = 'dashboard.view';

    case GUESTS_VIEW = 'guests.view';
    case GUESTS_CREATE = 'guests.create';
    case GUESTS_UPDATE = 'guests.update';
    case GUESTS_DELETE = 'guests.delete';

    case HISTORY_VIEW = 'history.view';

    case HOTEL_POLICIES_VIEW = 'hotel_policies.view';
    case HOTEL_POLICIES_CREATE = 'hotel_policies.create';
    case HOTEL_POLICIES_UPDATE = 'hotel_policies.update';
    case HOTEL_POLICIES_DELETE = 'hotel_policies.delete';

    case KNOWLEDGE_BASE_ARTICLES_VIEW = 'knowledge_base_articles.view';
    case KNOWLEDGE_BASE_ARTICLES_CREATE = 'knowledge_base_articles.create';
    case KNOWLEDGE_BASE_ARTICLES_UPDATE = 'knowledge_base_articles.update';
    case KNOWLEDGE_BASE_ARTICLES_DELETE = 'knowledge_base_articles.delete';

    case RECOMMENDATIONS_VIEW = 'recommendations.view';
    case RECOMMENDATIONS_UPDATE = 'recommendations.update';
    case RECOMMENDATIONS_DELETE = 'recommendations.delete';
    case RECOMMENDATIONS_GENERATE = 'recommendations.generate';
    case RECOMMENDATIONS_RECORD_OUTCOME = 'recommendations.record_outcome';

    case RESERVATIONS_VIEW = 'reservations.view';
    case RESERVATIONS_CREATE = 'reservations.create';
    case RESERVATIONS_UPDATE = 'reservations.update';
    case RESERVATIONS_DELETE = 'reservations.delete';
    case RESERVATIONS_IMPORT = 'reservations.import';

    case ROOMS_VIEW = 'rooms.view';
    case ROOMS_CREATE = 'rooms.create';
    case ROOMS_UPDATE = 'rooms.update';
    case ROOMS_DELETE = 'rooms.delete';

    case TASK_CATEGORIES_VIEW = 'task_categories.view';
    case TASK_CATEGORIES_CREATE = 'task_categories.create';
    case TASK_CATEGORIES_UPDATE = 'task_categories.update';
    case TASK_CATEGORIES_DELETE = 'task_categories.delete';

    case TASKS_VIEW = 'tasks.view';
    case TASKS_CREATE = 'tasks.create';
    case TASKS_UPDATE = 'tasks.update';
    case TASKS_DELETE = 'tasks.delete';

    case TEAMS_VIEW = 'teams.view';
    case TEAMS_CREATE = 'teams.create';
    case TEAMS_UPDATE = 'teams.update';
    case TEAMS_DELETE = 'teams.delete';

    case TRANSACTIONS_VIEW = 'transactions.view';
    case TRANSACTIONS_IMPORT = 'transactions.import';
    case TRANSACTIONS_REVERSE = 'transactions.reverse';

    /**
     * What an employee without a staff role can do: exactly the access every
     * employee had before staff roles existed, so leaving a role unassigned
     * changes nothing.
     *
     * - Guests and activities are readable because a booking taken at the desk
     *   needs a guest and an activity picked from those lists.
     * - Bookings can be taken and moved through their lifecycle by whoever is
     *   serving the guest, since only the outlet knows if the guest turned up.
     * - Recommendation outcomes are recorded by the people at the desk when a
     *   guest says no; a refusal-capture tool only admins can use captures
     *   nothing.
     *
     * @return list<self>
     */
    public static function employeeDefaults(): array
    {
        return [
            self::ACTIVITIES_VIEW,
            self::BOOKINGS_VIEW,
            self::BOOKINGS_CREATE,
            self::BOOKINGS_UPDATE_STATUS,
            self::DASHBOARD_VIEW,
            self::GUESTS_VIEW,
            self::RECOMMENDATIONS_RECORD_OUTCOME,
        ];
    }

    /**
     * The resource this permission is about, e.g. `guests`.
     */
    public function group(): string
    {
        return strstr($this->value, '.', true);
    }

    /**
     * What it allows on that resource, e.g. `view` or `update_status`.
     */
    public function action(): string
    {
        return substr(strrchr($this->value, '.'), 1);
    }
}
