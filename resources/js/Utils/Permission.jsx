import { usePage } from "@inertiajs/react";

export default function hasAnyPermission(permissions) {
    // destruct auth from usepage props
    const { auth } = usePage().props;

    // Jaga-jaga jika data auth atau auth.permissions belum terdefinisi (undefined)
    if (!auth || !auth.permissions) {
        return false;
    }

    // get all permissions from props auth.permissions
    let allPermissions = auth.permissions;

    // define has permission is false
    let hasPermission = false;

    // loop permissions
    permissions.forEach(function (item) {
        // do it if permission is match with key dan pastikan allPermissions tidak null
        if (allPermissions && allPermissions[item]) {
            // assign hasPermission to true
            hasPermission = true;
        }
    });

    // return has permissions
    return hasPermission;
}