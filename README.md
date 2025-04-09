# Yen Ming Temple Donation System Plugin

![WordPress](https://img.shields.io/badge/WordPress-%23117AC9.svg?style=for-the-badge&logo=WordPress&logoColor=white)
![PHP](https://img.shields.io/badge/php-%23777BB4.svg?style=for-the-badge&logo=php&logoColor=white)

A custom WordPress plugin that extends Formidable Forms' payment functionality to handle multiple donations in a cart-like system for Yen Ming Temple.

## ✨ Features

- 🛒 **Donation Cart System** - Allows users to add multiple donations before checkout
- 💳 **Payment Processing** - Integrates with Formidable Forms' Stripe payment system
- 👤 **User Tracking** - Works for both logged-in users and guests (using session cookies)
- 📊 **Donation Management** - Users can edit amounts or remove donations before payment
- 🌐 **Multilingual Support** - Compatible with Polylang for English/Chinese interface
- 📦 **Database Tracking** - Stores donation items in a custom database table
- 🔄 **AJAX Handling** - Real-time updates for donation amounts and deletions

## 🚀 Installation

1. Upload the plugin files to `/wp-content/plugins/yenming-donation-system`
2. Activate the plugin through WordPress admin
3. The plugin will automatically create the required database table

## 📦 Dependencies

- [Formidable Forms](https://wordpress.org/plugins/formidable/) (with Stripe payment add-on)
- [Polylang](https://wordpress.org/plugins/polylang/) (optional, for multilingual support)

## 💻 Usage

1. Add donation forms throughout the site (pre-configured form IDs: 13, 6, 11, 8, 2, 18, 10, 14, 5, 21, 24, 29, 31, 32, 34, 35)
   - You can set your own array of form id
2. Use the `[display_donations_checkout]` shortcode to display donation cart
3. Users can proceed to checkout which validates the total amount before payment
4. The plugin works for both logged-in and non-loggedin users by utilizing the userId and sessionId to retreived the records associated with them.

## 🔧 Technical Details

### Database Schema
Table: `wp_donations_item`

| Column          | Type      | Description                      |
|-----------------|-----------|----------------------------------|
| id              | BIGINT    | Auto-incrementing primary key    |
| user_id         | BIGINT    | User ID (for logged-in users)    |
| session_id      | VARCHAR   | Session ID (for guest users)     |
| form_id         | BIGINT    | Formidable Form ID               |
| entry_id        | BIGINT    | Formidable Entry ID              |
| donation_amount | DECIMAL   | Donation amount                  |
| form_name       | VARCHAR   | Name of the donation form        |
| created_at      | DATETIME  | Timestamp of creation            |

### API Endpoints
- `GET /wp-json/custom/v1/donations/`  
  Retrieves donations for current user/session

### AJAX Actions
- `update_donation_amount` - Updates donation amount
- `delete_donation` - Removes donation from cart

## 🔒 Security Features

- Nonce verification for all AJAX requests
- Session validation for guest users
- Amount validation before payment processing
- Automatic cleanup of records after successful payment

## CSS Modification

- Modify the CSS to your liking and needs.
