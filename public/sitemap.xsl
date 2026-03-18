<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="2.0"
    xmlns:html="http://www.w3.org/TR/REC-html40"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xhtml="http://www.w3.org/1999/xhtml"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

    <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>

    <xsl:template match="/">
        <html xmlns="http://www.w3.org/1999/xhtml">
        <head>
            <title>XML Sitemap — ListenAI Studio</title>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <style type="text/css">
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #333; background: #f5f5f5; padding: 2rem; }
                h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: .5rem; color: #111; }
                p.desc { color: #666; margin-bottom: 1.5rem; font-size: .9rem; }
                table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
                th { background: #111; color: #fff; text-align: left; padding: .75rem 1rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; }
                td { padding: .6rem 1rem; border-bottom: 1px solid #eee; font-size: .85rem; }
                tr:last-child td { border-bottom: none; }
                tr:hover td { background: #f9f9f9; }
                a { color: #2563eb; text-decoration: none; }
                a:hover { text-decoration: underline; }
                .priority { text-align: center; }
                .freq { text-align: center; }
            </style>
        </head>
        <body>
            <h1>XML Sitemap</h1>
            <p class="desc">
                This sitemap contains <xsl:value-of select="count(sitemap:urlset/sitemap:url)"/> URLs.
            </p>
            <table>
                <tr>
                    <th>URL</th>
                    <th>Priority</th>
                    <th>Change Freq</th>
                    <th>Last Modified</th>
                </tr>
                <xsl:for-each select="sitemap:urlset/sitemap:url">
                    <xsl:sort select="sitemap:priority" order="descending" data-type="number"/>
                    <tr>
                        <td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td>
                        <td class="priority"><xsl:value-of select="sitemap:priority"/></td>
                        <td class="freq"><xsl:value-of select="sitemap:changefreq"/></td>
                        <td><xsl:value-of select="substring(sitemap:lastmod, 1, 10)"/></td>
                    </tr>
                </xsl:for-each>
            </table>
        </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
