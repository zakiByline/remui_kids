<?php
/**
 * Shared renderer utilities for standalone emulator launch pages.
 *
 * @package   theme_remui_kids
 * @copyright 2025 Kodeit
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Render a standardised iframe-based emulator launcher.
 *
 * @param string $title     Heading shown on the page.
 * @param string $subtitle  Short description shown under the title.
 * @param string $badge     Category/label displayed next to the title.
 * @param string $iframeurl URL of the tool to embed.
 * @param array  $features  Optional list of bullet points displayed above the iframe.
 */
function theme_remui_kids_render_iframe_emulator_page(
    string $title,
    string $subtitle,
    string $badge,
    string $iframeurl,
    array $features = []
): void {
    global $OUTPUT;

    $frameid = 'emulatorFrame_' . random_string(6);

    echo $OUTPUT->header();
    ?>
    <div class="standalone-emulator-shell">
        <div class="emulator-hero">
            <div>
                <span class="hero-badge"><?php echo s($badge); ?></span>
                <h1><?php echo format_string($title); ?></h1>
                <p><?php echo s($subtitle); ?></p>
            </div>
            <div class="hero-actions">
                <button type="button" class="ghost-btn" onclick="document.getElementById('<?php echo $frameid; ?>').src='<?php echo s($iframeurl); ?>';">
                    <i class="fa fa-rotate-right"></i>
                    <?php echo get_string('reload', 'moodle'); ?>
                </button>
            </div>
        </div>

        <?php if (!empty($features)): ?>
            <ul class="emulator-feature-list">
                <?php foreach ($features as $feature): ?>
                    <li><?php echo s($feature); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="emulator-frame">
            <iframe id="<?php echo $frameid; ?>"
                    src="<?php echo s($iframeurl); ?>"
                    allow="clipboard-read; clipboard-write; fullscreen"
                    loading="lazy"></iframe>
        </div>
    </div>

    <style>
        .standalone-emulator-shell {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem 3rem;
        }

        .emulator-hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.5rem;
            padding: 1.5rem 2rem;
            border-radius: 18px;
            background: linear-gradient(135deg, #eef2ff 0%, #e0f7ff 100%);
        }

        .emulator-hero h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
            color: #0f172a;
        }

        .emulator-hero p {
            margin: 0;
            color: #475569;
            font-size: 1rem;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #2563eb;
            background: rgba(37, 99, 235, 0.12);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.75rem;
        }

        .hero-actions {
            display: flex;
            gap: 0.75rem;
        }

        .ghost-btn {
            border: 1px solid rgba(15, 23, 42, 0.15);
            background: #fff;
            color: #0f172a;
            padding: 0.55rem 1.4rem;
            border-radius: 999px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .ghost-btn:hover {
            border-color: #2563eb;
            color: #2563eb;
        }

        .emulator-feature-list {
            margin: 1.5rem 0 0.5rem 0;
            padding-left: 1.2rem;
            color: #475569;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0.4rem 1rem;
        }

        .emulator-feature-list li {
            list-style: disc;
            line-height: 1.4;
        }

        .emulator-frame {
            margin-top: 1.5rem;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.15);
            background: #0f172a;
        }

        .emulator-frame iframe {
            width: 100%;
            height: calc(100vh - 280px);
            min-height: 620px;
            border: none;
            display: block;
        }

        @media (max-width: 768px) {
            .standalone-emulator-shell {
                padding: 1.5rem 1rem 2rem;
            }

            .emulator-hero {
                flex-direction: column;
            }

            .emulator-frame iframe {
                min-height: 520px;
                height: calc(100vh - 220px);
            }
        }
    </style>
    <?php
    echo $OUTPUT->footer();
}

